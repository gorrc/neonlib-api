<?php
declare(strict_types=1);
namespace NeonLib;

use PDO;

final class AdminApiRepository
{
    public function __construct(private readonly PDO $database, private readonly int $adminUserId) {}

    public function accounts(array $query): array
    {
        $status = strtoupper(trim((string) ($query['status'] ?? '')));
        if ($status !== '' && !in_array($status, ['ACTIVE', 'SUSPENDED', 'DELETED'], true)) throw new ApiException(422, 'invalid_filter', 'Invalid account status filter.');
        $search = trim((string) ($query['q'] ?? ''));
        $sql = "SELECT a.account_id, a.status, a.created_at, a.updated_at,
                       COUNT(DISTINCT s.id) AS subscription_count,
                       COUNT(DISTINCT CASE WHEN l.revoked_at IS NULL THEN l.id END) AS active_link_count
                FROM accounts a LEFT JOIN subscriptions s ON s.owner_account_id = a.account_id
                LEFT JOIN external_account_links l ON l.account_id = a.account_id WHERE 1=1";
        $params = [];
        if ($status !== '') { $sql .= ' AND a.status = :status'; $params['status'] = $status; }
        if ($search !== '') { $sql .= ' AND a.account_id LIKE :search'; $params['search'] = '%' . $search . '%'; }
        $sql .= ' GROUP BY a.account_id, a.status, a.created_at, a.updated_at ORDER BY a.created_at DESC LIMIT 100';
        $statement = $this->database->prepare($sql); $statement->execute($params);
        return array_map([$this, 'accountResponse'], $statement->fetchAll());
    }

    public function account(string $accountId): array
    {
        $statement = $this->database->prepare(
            'SELECT a.account_id, a.status, a.created_at, a.updated_at,
                    COUNT(DISTINCT s.id) AS subscription_count,
                    COUNT(DISTINCT CASE WHEN l.revoked_at IS NULL THEN l.id END) AS active_link_count
             FROM accounts a LEFT JOIN subscriptions s ON s.owner_account_id = a.account_id
             LEFT JOIN external_account_links l ON l.account_id = a.account_id
             WHERE a.account_id = :account GROUP BY a.account_id, a.status, a.created_at, a.updated_at'
        );
        $statement->execute(['account' => $accountId]); $row = $statement->fetch();
        if (!$row) throw new ApiException(404, 'account_not_found', 'Account not found.');
        return $this->accountResponse($row);
    }

    public function updateAccount(string $accountId, array $input): array
    {
        if (count($input) !== 1 || !isset($input['status']) || !is_string($input['status'])) throw new ApiException(422, 'validation_failed', 'Only status is editable.');
        $status = strtoupper($input['status']);
        if (!in_array($status, ['ACTIVE', 'SUSPENDED', 'DELETED'], true)) throw new ApiException(422, 'validation_failed', 'Invalid account status.');
        $statement = $this->database->prepare('UPDATE accounts SET status = :status WHERE account_id = :account');
        $statement->execute(['status' => $status, 'account' => $accountId]);
        if ($statement->rowCount() === 0) $this->account($accountId);
        $this->audit('admin_account_status_changed', null, ['account_id' => $accountId, 'status' => $status]);
        return $this->account($accountId);
    }

    public function subscriptions(array $query): array
    {
        $status = strtoupper(trim((string) ($query['status'] ?? '')));
        if ($status !== '' && !in_array($status, ['DRAFT', 'PUBLISHED', 'ARCHIVED'], true)) throw new ApiException(422, 'invalid_filter', 'Invalid subscription status filter.');
        $sql = 'SELECT s.package_id, s.owner_account_id, s.title, s.language, s.visibility, s.status, s.is_featured,
                       s.created_at, s.updated_at, p.slug AS publisher_slug, (SELECT COUNT(*) FROM subscription_versions pending WHERE pending.subscription_id = s.id AND pending.status = \'DRAFT\') AS pending_count
                FROM subscriptions s JOIN publishers p ON p.id = s.publisher_id WHERE 1=1';
        $params = [];
        if ($status !== '') { $sql .= ' AND s.status = :status'; $params['status'] = $status; }
        if (isset($query['account_id']) && $query['account_id'] !== '') { $sql .= ' AND s.owner_account_id = :account'; $params['account'] = (string) $query['account_id']; }
        $sql .= ' ORDER BY s.updated_at DESC LIMIT 100';
        $statement = $this->database->prepare($sql); $statement->execute($params);
        return array_map([$this, 'subscriptionResponse'], $statement->fetchAll());
    }

    public function subscription(string $packageId): array
    {
        $statement = $this->database->prepare(
            'SELECT s.package_id, s.owner_account_id, s.title, s.language, s.visibility, s.status, s.is_featured,
                    s.created_at, s.updated_at, p.slug AS publisher_slug, (SELECT COUNT(*) FROM subscription_versions pending WHERE pending.subscription_id = s.id AND pending.status = \'DRAFT\') AS pending_count
             FROM subscriptions s JOIN publishers p ON p.id = s.publisher_id WHERE s.package_id = :package LIMIT 1'
        );
        $statement->execute(['package' => $packageId]); $row = $statement->fetch();
        if (!$row) throw new ApiException(404, 'subscription_not_found', 'Subscription not found.');
        $response = $this->subscriptionResponse($row);
        $response['review'] = (new SubscriptionReview($this->database))->get($packageId);
        $response['editor'] = (new AdminSubscriptionEditor($this->database))->snapshot($packageId);
        return $response;
    }

    public function updateSubscription(string $packageId, array $input): array
    {
        if (array_key_exists('edit', $input)) {
            if (count($input) !== 1 || !is_array($input['edit'])) throw new ApiException(422, 'validation_failed', 'Only the edit object is accepted.');
            (new AdminSubscriptionEditor($this->database))->save($packageId, $input['edit'], $this->adminUserId);
            return $this->subscription($packageId);
        }
        if (isset($input['approve_version'])) {
            (new SubscriptionReview($this->database))->approve($packageId, $input, $this->adminUserId);
            return $this->subscription($packageId);
        }
        if (strtoupper((string) ($input['status'] ?? '')) === 'PUBLISHED') {
            throw new ApiException(422, 'review_required', 'Open the document review and approve the reviewed version.');
        }
        $allowed = ['status', 'visibility', 'is_featured'];
        if ($input === [] || array_diff(array_keys($input), $allowed)) throw new ApiException(422, 'validation_failed', 'Only status, visibility and is_featured are editable.');
        $values = []; $sets = [];
        // Changing visibility also requires a fresh approval. Preserve archive.
        if (isset($input['visibility']) && !isset($input['status'])) $sets[] = "status = CASE WHEN status = 'ARCHIVED' THEN 'ARCHIVED' ELSE 'DRAFT' END";
        if (isset($input['status'])) { $value = strtoupper((string) $input['status']); if (!in_array($value, ['DRAFT','PUBLISHED','ARCHIVED'], true)) throw new ApiException(422,'validation_failed','Invalid status.'); $values['status']=$value; $sets[]='status = :status'; }
        if (isset($input['visibility'])) { $value = strtoupper((string) $input['visibility']); if (!in_array($value,['PUBLIC','PRIVATE'],true)) throw new ApiException(422,'validation_failed','Invalid visibility.'); $values['visibility']=$value; $sets[]='visibility = :visibility'; }
        if (array_key_exists('is_featured',$input)) { if (!is_bool($input['is_featured'])) throw new ApiException(422,'validation_failed','is_featured must be boolean.'); $values['featured']=$input['is_featured']?1:0; $sets[]='is_featured = :featured'; }
        if (!$sets) throw new ApiException(422, 'validation_failed', 'At least one field is required.');
        $statement=$this->database->prepare('UPDATE subscriptions SET '.implode(', ',$sets).' WHERE package_id = :package');
        $statement->execute($values + ['package'=>$packageId]); if ($statement->rowCount()===0) $this->subscription($packageId);
        $this->audit('admin_subscription_moderated', $packageId, $input);
        return $this->subscription($packageId);
    }

    public function deleteSubscriptions(array $input): array
    {
        $ids = $input['package_ids'] ?? null;
        if (array_diff(array_keys($input), ['package_ids', 'confirm']) || ($input['confirm'] ?? false) !== true ||
            !is_array($ids) || !array_is_list($ids) || count($ids) < 1 || count($ids) > 100) {
            throw new ApiException(422, 'validation_failed', 'Confirm deletion of 1–100 selected collections.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9._-]{1,189}$/', $id)) throw new ApiException(422, 'validation_failed', 'Invalid package ID.');
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        $this->database->beginTransaction();
        try {
            $lock = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $query = $this->database->prepare('SELECT id, package_id FROM subscriptions WHERE package_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id' . $lock);
            $query->execute($ids);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($ids)) throw new ApiException(409, 'subscriptions_changed', 'Some collections no longer exist. Refresh the list.');
            $delete = $this->database->prepare('DELETE FROM subscriptions WHERE id = :id');
            foreach ($rows as $row) {
                // Schema cascades to versions and documents. Audit must succeed in the same transaction.
                $this->audit('admin_subscription_deleted', $row['package_id'], ['permanent'=>true]);
                $delete->execute(['id'=>$row['id']]);
            }
            $this->database->commit();
            return ['deleted'=>count($rows)];
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    private function audit(string $event, ?string $packageId, array $details): void
    {
        $statement=$this->database->prepare('INSERT INTO admin_audit_log (user_id,event_type,package_id,ip_address,details) VALUES (:user,:event,:package,:ip,:details)');
        $statement->execute(['user'=>$this->adminUserId,'event'=>$event,'package'=>$packageId,'ip'=>$_SERVER['REMOTE_ADDR']??'cli','details'=>json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }

    private function accountResponse(array $row): array { return ['account_id'=>$row['account_id'],'status'=>strtolower($row['status']),'subscription_count'=>(int)$row['subscription_count'],'active_link_count'=>(int)$row['active_link_count'],'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']]; }
    private function subscriptionResponse(array $row): array { return ['pending_count'=>(int)$row['pending_count'],'package_id'=>$row['package_id'],'account_id'=>$row['owner_account_id'],'publisher_slug'=>$row['publisher_slug'],'title'=>$row['title'],'language'=>$row['language'],'visibility'=>strtolower($row['visibility']),'status'=>strtolower($row['status']),'is_featured'=>(bool)$row['is_featured'],'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']]; }
}

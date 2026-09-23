<?php
declare(strict_types=1);
namespace NeonLib;

use PDO;
use PDOException;

final class OwnedSubscriptionRepository
{
    public function __construct(private readonly PDO $database) {}

    public function publisher(string $accountId): ?array
    {
        $query = $this->database->prepare(
            'SELECT slug, name FROM publishers WHERE owner_account_id = :account LIMIT 1'
        );
        $query->execute(['account' => $accountId]);
        $row = $query->fetch();
        return $row ? ['slug' => $row['slug'], 'display_name' => $row['name']] : null;
    }

    public function updatePublisher(string $accountId, array $input): array
    {
        if (array_keys($input) !== ['display_name'] || !is_string($input['display_name'])) {
            throw new ApiException(422, 'validation_failed', 'Only display_name is accepted.');
        }
        $name = trim($input['display_name']);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new ApiException(422, 'validation_failed', 'Publisher display name must contain 1–160 characters.');
        }

        $existing = $this->publisher($accountId);
        if ($existing !== null && $existing['display_name'] === $name) return $existing;
        if ($existing === null) {
            $slug = 'account-' . substr($accountId, 4);
            $statement = $this->database->prepare(
                'INSERT INTO publishers (owner_account_id, slug, name) VALUES (:account, :slug, :name)'
            );
            $statement->execute(['account' => $accountId, 'slug' => $slug, 'name' => $name]);
        } else {
            $this->database->beginTransaction();
            try {
                $statement = $this->database->prepare('UPDATE publishers SET name = :name WHERE owner_account_id = :account');
                $statement->execute(['account' => $accountId, 'name' => $name]);
                { // Every actual rename invalidates existing approvals.
                    $this->database->prepare("UPDATE subscriptions SET status = 'DRAFT' WHERE owner_account_id = :account AND status = 'PUBLISHED'")
                        ->execute(['account' => $accountId]);
                }
                $this->database->commit();
            } catch (\Throwable $error) {
                if ($this->database->inTransaction()) $this->database->rollBack();
                throw $error;
            }
        }

        return $this->publisher($accountId) ?? throw new \RuntimeException('Publisher profile was not persisted.');
    }

    public function list(string $accountId): array
    {
        $query = $this->database->prepare(
            'SELECT package_id, title, description, language, visibility, status, created_at, updated_at
             FROM subscriptions WHERE owner_account_id = :account ORDER BY updated_at DESC, id DESC'
        );
        $query->execute(['account' => $accountId]);
        return array_map([$this, 'response'], $query->fetchAll());
    }

    public function get(string $accountId, string $packageId): array
    {
        $query = $this->database->prepare(
            'SELECT package_id, title, description, language, visibility, status, created_at, updated_at
             FROM subscriptions WHERE owner_account_id = :account AND package_id = :package LIMIT 1'
        );
        $query->execute(['account' => $accountId, 'package' => $packageId]);
        $row = $query->fetch();
        if (!$row) {
            throw new ApiException(404, 'subscription_not_found', 'Subscription not found.');
        }
        return $this->response($row);
    }

    public function create(string $accountId, array $input): array
    {
        $values = $this->validate($input, false);
        $this->database->beginTransaction();
        try {
            $publisherId = $this->publisherId($accountId);
            $insert = $this->database->prepare(
                "INSERT INTO subscriptions (publisher_id, owner_account_id, package_id, title, description, language, visibility, status)
                 VALUES (:publisher, :account, :package, :title, :description, :language, :visibility, 'DRAFT')"
            );
            $insert->execute(['publisher' => $publisherId, 'account' => $accountId] + $values);
            $this->database->commit();
        } catch (PDOException $exception) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            if ($exception->getCode() === '23000') {
                throw new ApiException(409, 'package_id_conflict', 'Package ID is already in use.');
            }
            throw $exception;
        }
        return $this->get($accountId, $values['package']);
    }

    public function update(string $accountId, string $packageId, array $input): array
    {
        $values = $this->validate($input, true);
        if ($values === []) {
            throw new ApiException(422, 'validation_failed', 'At least one editable field is required.');
        }
        $sets = [];
        foreach ($values as $column => $_) $sets[] = $column . ' = :' . $column;
        $statement = $this->database->prepare(
            "UPDATE subscriptions SET status = CASE WHEN status = 'ARCHIVED' THEN 'ARCHIVED' ELSE 'DRAFT' END, " . implode(', ', $sets) .
            ' WHERE owner_account_id = :account AND package_id = :current_package'
        );
        try {
            $statement->execute($values + ['account' => $accountId, 'current_package' => $packageId]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') throw new ApiException(409, 'package_id_conflict', 'Package ID is already in use.');
            throw $exception;
        }
        if ($statement->rowCount() === 0) $this->get($accountId, $packageId);
        return $this->get($accountId, $values['package_id'] ?? $packageId);
    }

    public function delete(string $accountId, string $packageId): void
    {
        $statement = $this->database->prepare(
            'DELETE FROM subscriptions WHERE owner_account_id = :account AND package_id = :package'
        );
        $statement->execute(['account' => $accountId, 'package' => $packageId]);
        if ($statement->rowCount() === 0) throw new ApiException(404, 'subscription_not_found', 'Subscription not found.');
    }

    private function publisherId(string $accountId): int
    {
        $query = $this->database->prepare('SELECT id FROM publishers WHERE owner_account_id = :account LIMIT 1');
        $query->execute(['account' => $accountId]);
        $id = $query->fetchColumn();
        if ($id !== false) return (int) $id;
        $slug = 'account-' . substr($accountId, 4);
        $insert = $this->database->prepare(
            'INSERT INTO publishers (owner_account_id, slug, name) VALUES (:account, :slug, :name)'
        );
        $insert->execute(['account' => $accountId, 'slug' => $slug, 'name' => 'NeonLib account']);
        return (int) $this->database->lastInsertId();
    }

    private function validate(array $input, bool $partial): array
    {
        $allowed = ['package_id', 'title', 'description', 'language', 'visibility'];
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown) throw new ApiException(422, 'validation_failed', 'Unknown fields are not allowed.', ['fields' => array_values($unknown)]);
        $errors = []; $values = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $input)) {
                if (!$partial) $errors[$field] = 'Field is required.';
                continue;
            }
            $value = $input[$field];
            if (!is_string($value)) { $errors[$field] = 'Must be a string.'; continue; }
            $value = trim($value);
            $valid = match ($field) {
                'package_id' => (bool) preg_match('/^[a-z0-9][a-z0-9._-]{2,189}$/', $value),
                'title' => mb_strlen($value) >= 1 && mb_strlen($value) <= 190,
                'description' => mb_strlen($value) <= 10000,
                'language' => (bool) preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i', $value),
                'visibility' => in_array(strtoupper($value), ['PUBLIC', 'PRIVATE'], true),
            };
            if (!$valid) { $errors[$field] = 'Invalid value.'; continue; }
            $column = $field === 'package_id' && !$partial ? 'package' : $field;
            $values[$column] = $field === 'visibility' ? strtoupper($value) : ($field === 'language' ? strtolower($value) : $value);
        }
        if ($errors) throw new ApiException(422, 'validation_failed', 'Request validation failed.', ['fields' => $errors]);
        return $values;
    }

    private function response(array $row): array
    {
        return ['package_id' => $row['package_id'], 'title' => $row['title'], 'description' => $row['description'],
            'language' => $row['language'], 'visibility' => strtolower($row['visibility']), 'status' => strtolower($row['status']),
            'created_at' => gmdate(DATE_ATOM, strtotime($row['created_at'] . ' UTC')),
            'updated_at' => gmdate(DATE_ATOM, strtotime($row['updated_at'] . ' UTC'))];
    }
}

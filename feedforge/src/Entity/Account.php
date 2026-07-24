<?php

declare(strict_types=1);

/**
 * Feed Forge - Google Merchant Center integration for PrestaShop
 *
 * @author    Feed Forge
 * @copyright Feed Forge
 * @license   MIT
 */

namespace FeedForge\Entity;

/**
 * Data class representing a row in the ps_feedforge_account table.
 *
 * Stores Google Merchant Center account credentials and OAuth tokens
 * associated with a specific shop.
 */
class Account
{
    public int $id_feedforge_account = 0;
    public int $id_shop = 0;
    public string $merchant_id = '';
    public string $merchant_name = '';
    public string $email = '';
    public string $access_token = '';
    public string $refresh_token = '';
    public ?string $token_expires_at = null;
    public ?string $encryption_iv = null;
    public bool $active = true;
    public ?string $date_add = null;
    public ?string $date_upd = null;

    /**
     * Create an Account instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_feedforge_account = (int) ($data['id_feedforge_account'] ?? 0);
        $entity->id_shop = (int) ($data['id_shop'] ?? 0);
        $entity->merchant_id = (string) ($data['merchant_id'] ?? '');
        $entity->merchant_name = (string) ($data['merchant_name'] ?? '');
        $entity->email = (string) ($data['email'] ?? '');
        $entity->access_token = (string) ($data['access_token'] ?? '');
        $entity->refresh_token = (string) ($data['refresh_token'] ?? '');
        $entity->token_expires_at = $data['token_expires_at'] ?? null;
        $entity->encryption_iv = $data['encryption_iv'] ?? null;
        $entity->active = (bool) ($data['active'] ?? true);
        $entity->date_add = $data['date_add'] ?? null;
        $entity->date_upd = $data['date_upd'] ?? null;

        return $entity;
    }
}

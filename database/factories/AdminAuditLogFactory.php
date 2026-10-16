<?php

namespace Database\Factories;

use App\Models\AdminAuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminAuditLog>
 */
class AdminAuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory()->superAdmin(),
            'action' => AdminAuditLog::ACTION_STORE_UPDATED,
            'subject_type' => AdminAuditLog::SUBJECT_STORE,
            'subject_id' => Store::factory(),
            'details' => null,
        ];
    }
}

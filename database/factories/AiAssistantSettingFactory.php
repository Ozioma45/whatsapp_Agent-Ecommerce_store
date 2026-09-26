<?php

namespace Database\Factories;

use App\Models\AiAssistantSetting;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAssistantSetting>
 */
class AiAssistantSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'enabled' => false,
            'welcome_message' => AiAssistantSetting::DEFAULT_WELCOME_MESSAGE,
            'business_instructions' => null,
            'tone' => AiAssistantSetting::TONE_FRIENDLY,
        ];
    }
}

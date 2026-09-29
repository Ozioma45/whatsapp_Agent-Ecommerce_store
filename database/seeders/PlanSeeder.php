<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seeds the three initial SaaS plans, the initial feature set, and each
 * plan's entitlements — then backfills any existing business that doesn't
 * have a plan yet onto Standard.
 *
 * Safe to re-run: every write is an updateOrCreate keyed on the natural
 * unique key (a slug, a feature key, or a plan+feature pair), and the
 * business backfill only ever touches businesses with no plan_id — no
 * existing business or its data is ever deleted or reset.
 */
class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Prices are illustrative placeholders for the subscription pages
        // to display — like the entitlements above, not final commercial
        // pricing, and no payment gateway reads them (Phase 10A).
        $plans = [
            Plan::STANDARD => ['name' => 'Standard', 'description' => 'The default plan every new business starts on.', 'price' => 0],
            Plan::PRO => ['name' => 'Pro', 'description' => 'Adds custom branding and higher limits.', 'price' => 15000],
            Plan::PREMIUM => ['name' => 'Premium', 'description' => 'Everything, including the AI assistant, unlimited.', 'price' => 45000],
        ];

        $planModels = [];

        foreach ($plans as $slug => $attributes) {
            $planModels[$slug] = Plan::updateOrCreate(['slug' => $slug], $attributes + ['is_active' => true]);
        }

        $features = [
            Feature::STOREFRONT => ['name' => 'Storefront', 'type' => Feature::TYPE_BOOLEAN],
            Feature::WHATSAPP_ORDERING => ['name' => 'WhatsApp ordering', 'type' => Feature::TYPE_BOOLEAN],
            Feature::ORDER_MANAGEMENT => ['name' => 'Order management', 'type' => Feature::TYPE_BOOLEAN],
            Feature::PRODUCTS_LIMIT => ['name' => 'Product limit', 'type' => Feature::TYPE_LIMIT],
            Feature::CATEGORIES => ['name' => 'Categories', 'type' => Feature::TYPE_BOOLEAN],
            Feature::CUSTOM_BRANDING => ['name' => 'Custom branding', 'type' => Feature::TYPE_BOOLEAN],
            Feature::AI_ASSISTANT => ['name' => 'AI assistant', 'type' => Feature::TYPE_BOOLEAN],
        ];

        $featureModels = [];

        foreach ($features as $key => $attributes) {
            $featureModels[$key] = Feature::updateOrCreate(['key' => $key], $attributes);
        }

        // Initial technical entitlements only — not final commercial plans.
        $entitlements = [
            Plan::STANDARD => [
                Feature::STOREFRONT => ['enabled' => true],
                Feature::WHATSAPP_ORDERING => ['enabled' => true],
                Feature::ORDER_MANAGEMENT => ['enabled' => true],
                Feature::CATEGORIES => ['enabled' => true],
                Feature::PRODUCTS_LIMIT => ['enabled' => true, 'limit' => 20],
                Feature::CUSTOM_BRANDING => ['enabled' => false],
                Feature::AI_ASSISTANT => ['enabled' => false],
            ],
            Plan::PRO => [
                Feature::STOREFRONT => ['enabled' => true],
                Feature::WHATSAPP_ORDERING => ['enabled' => true],
                Feature::ORDER_MANAGEMENT => ['enabled' => true],
                Feature::CATEGORIES => ['enabled' => true],
                Feature::PRODUCTS_LIMIT => ['enabled' => true, 'limit' => 100],
                Feature::CUSTOM_BRANDING => ['enabled' => true],
                Feature::AI_ASSISTANT => ['enabled' => false],
            ],
            Plan::PREMIUM => [
                Feature::STOREFRONT => ['enabled' => true],
                Feature::WHATSAPP_ORDERING => ['enabled' => true],
                Feature::ORDER_MANAGEMENT => ['enabled' => true],
                Feature::CATEGORIES => ['enabled' => true],
                // A null limit means unlimited (see PlanFeatureService).
                Feature::PRODUCTS_LIMIT => ['enabled' => true, 'limit' => null],
                Feature::CUSTOM_BRANDING => ['enabled' => true],
                Feature::AI_ASSISTANT => ['enabled' => true],
            ],
        ];

        foreach ($entitlements as $planSlug => $planFeatures) {
            foreach ($planFeatures as $featureKey => $pivot) {
                $planModels[$planSlug]->features()->syncWithoutDetaching([
                    $featureModels[$featureKey]->id => [
                        'enabled' => $pivot['enabled'],
                        'limit' => $pivot['limit'] ?? null,
                    ],
                ]);
            }
        }

        // Every existing business must have a plan too — default to
        // Standard, without touching businesses that already have one.
        Business::whereNull('plan_id')->update(['plan_id' => $planModels[Plan::STANDARD]->id]);
    }
}

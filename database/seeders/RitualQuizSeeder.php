<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\RitualQuizOption;
use App\Models\RitualQuizQuestion;
use Illuminate\Database\Seeder;

/**
 * Seeds a small bilingual ritual quiz with weighted product recommendations
 * so dashboard + storefront quiz flows can be tested locally.
 */
class RitualQuizSeeder extends Seeder
{
    public function run(): void
    {
        $bySlug = Product::query()
            ->whereIn('slug', [
                'pure-argan-oil',
                'moroccan-black-soap',
                'rose-body-scrub',
                'neroli-face-mist',
                'prickly-pear-face-oil',
                'rhassoul-clay-mask',
                'saffron-calm-drops',
                'repair-hair-mask',
                'damask-rose-water',
                'fig-amber-candle',
                'traditional-kessa-glove',
                'desert-sage-tea',
            ])
            ->pluck('id', 'slug');

        if ($bySlug->isEmpty()) {
            $this->command?->warn('RitualQuizSeeder skipped: no catalog products found. Run StorefrontCatalogSeeder first.');
            return;
        }

        $quiz = [
            [
                'question' => 'What is your skin asking for right now?',
                'question_ar' => 'ماذا تطلب بشرتك الآن؟',
                'sort_order' => 1,
                'options' => [
                    [
                        'label' => 'Glow and brightness',
                        'label_ar' => 'إشراق ونضارة',
                        'products' => [
                            'neroli-face-mist' => 8,
                            'prickly-pear-face-oil' => 6,
                            'rose-body-scrub' => 4,
                        ],
                    ],
                    [
                        'label' => 'Deep calm and softness',
                        'label_ar' => 'هدوء ونعومة عميقة',
                        'products' => [
                            'saffron-calm-drops' => 9,
                            'damask-rose-water' => 5,
                            'fig-amber-candle' => 4,
                            'desert-sage-tea' => 3,
                        ],
                    ],
                    [
                        'label' => 'A proper cleanse',
                        'label_ar' => 'تنظيف حقيقي',
                        'products' => [
                            'moroccan-black-soap' => 9,
                            'rhassoul-clay-mask' => 7,
                            'traditional-kessa-glove' => 5,
                        ],
                    ],
                ],
            ],
            [
                'question' => 'Where will you begin the ritual?',
                'question_ar' => 'من أين ستبدأين الطقس؟',
                'sort_order' => 2,
                'options' => [
                    [
                        'label' => 'Face and neck',
                        'label_ar' => 'الوجه والرقبة',
                        'products' => [
                            'prickly-pear-face-oil' => 8,
                            'neroli-face-mist' => 7,
                            'damask-rose-water' => 4,
                        ],
                    ],
                    [
                        'label' => 'Hair and scalp',
                        'label_ar' => 'الشعر وفروة الرأس',
                        'products' => [
                            'pure-argan-oil' => 9,
                            'repair-hair-mask' => 8,
                        ],
                    ],
                    [
                        'label' => 'The whole body',
                        'label_ar' => 'الجسم كله',
                        'products' => [
                            'moroccan-black-soap' => 7,
                            'rose-body-scrub' => 6,
                            'traditional-kessa-glove' => 5,
                            'pure-argan-oil' => 3,
                        ],
                    ],
                ],
            ],
            [
                'question' => 'How much time do you have?',
                'question_ar' => 'كم من الوقت لديك؟',
                'sort_order' => 3,
                'options' => [
                    [
                        'label' => 'Five quiet minutes',
                        'label_ar' => 'خمس دقائق هادئة',
                        'products' => [
                            'neroli-face-mist' => 6,
                            'saffron-calm-drops' => 5,
                            'damask-rose-water' => 4,
                        ],
                    ],
                    [
                        'label' => 'A full hammam morning',
                        'label_ar' => 'صباح حمّام كامل',
                        'products' => [
                            'moroccan-black-soap' => 8,
                            'traditional-kessa-glove' => 7,
                            'rhassoul-clay-mask' => 6,
                            'pure-argan-oil' => 5,
                        ],
                    ],
                    [
                        'label' => 'An unhurried evening',
                        'label_ar' => 'مساء بلا عجلة',
                        'products' => [
                            'prickly-pear-face-oil' => 7,
                            'fig-amber-candle' => 6,
                            'saffron-calm-drops' => 5,
                            'desert-sage-tea' => 4,
                        ],
                    ],
                ],
            ],
        ];

        foreach ($quiz as $q) {
            $question = RitualQuizQuestion::updateOrCreate(
                ['question' => $q['question']],
                [
                    'question_ar' => $q['question_ar'],
                    'sort_order' => $q['sort_order'],
                    'is_active' => true,
                ]
            );

            foreach ($q['options'] as $index => $opt) {
                $option = RitualQuizOption::updateOrCreate(
                    [
                        'question_id' => $question->id,
                        'label' => $opt['label'],
                    ],
                    [
                        'label_ar' => $opt['label_ar'],
                        'sort_order' => $index + 1,
                        'is_active' => true,
                    ]
                );

                $sync = [];
                foreach ($opt['products'] as $slug => $weight) {
                    $productId = $bySlug[$slug] ?? null;
                    if ($productId) {
                        $sync[$productId] = ['weight' => $weight];
                    }
                }
                $option->products()->sync($sync);
            }
        }
    }
}

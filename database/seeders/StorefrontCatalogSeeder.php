<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\Product;
use App\Models\ProductMarketPrice;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\Ritual;
use App\Models\ShippingRule;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the static KHAM Cairo demo catalog (categories, brands, products,
 * ingredients, rituals) so the storefront/dashboard can be shown with sample data.
 */
class StorefrontCatalogSeeder extends Seeder
{
    private function assetUrl(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return rtrim((string) config('app.url'), '/') . $path;
    }

    public function run(): void
    {
        $market = Market::query()->where('code', 'EG')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'EG-01')->firstOrFail();
        $currencyId = $market->currency_id;

        ShippingRule::updateOrCreate(
            ['market_id' => $market->id, 'city_key' => '*'],
            [
                'shipping_fee' => 50,
                'free_shipping_threshold' => 1000,
                'is_active' => true,
            ]
        );

        $categories = [
            ['slug' => 'skincare', 'name' => 'Skincare', 'name_ar' => 'العناية بالبشرة', 'image' => '/images/products/night-cream.jpg', 'sort' => 1],
            ['slug' => 'bodycare', 'name' => 'Bodycare', 'name_ar' => 'العناية بالجسم', 'image' => '/images/products/soap.jpg', 'sort' => 2],
            ['slug' => 'haircare', 'name' => 'Haircare', 'name_ar' => 'العناية بالشعر', 'image' => '/images/hair.jpg', 'sort' => 3],
            ['slug' => 'fragrance', 'name' => 'Fragrance', 'name_ar' => 'العطور', 'image' => '/images/products/amber-perfume.jpg', 'sort' => 4],
            ['slug' => 'hammam', 'name' => 'Hammam', 'name_ar' => 'الحمّام', 'image' => '/images/products/black-soap.jpg', 'sort' => 5],
            ['slug' => 'wellness', 'name' => 'Wellness', 'name_ar' => 'العافية', 'image' => '/images/olive.jpg', 'sort' => 6],
            ['slug' => 'home', 'name' => 'Home & Lifestyle', 'name_ar' => 'المنزل', 'image' => '/images/products/candle.jpg', 'sort' => 7],
            ['slug' => 'gifts', 'name' => 'Gifts', 'name_ar' => 'الهدايا', 'image' => '/images/products/gift-set.jpg', 'sort' => 8],
        ];

        $categoryIds = [];
        foreach ($categories as $c) {
            $row = Category::updateOrCreate(
                ['slug' => $c['slug']],
                [
                    'name' => $c['name'],
                    'name_ar' => $c['name_ar'],
                    'description' => null,
                    'description_ar' => null,
                    'image_url' => $this->assetUrl($c['image']),
                    'sort_order' => $c['sort'],
                    'is_active' => true,
                    'parent_id' => null,
                ]
            );
            $categoryIds[$c['slug']] = $row->id;
        }

        $brands = [
            ['slug' => 'kham-cairo', 'name' => 'KHAM Cairo', 'name_ar' => 'خام القاهرة', 'origin' => 'EG', 'story' => 'KHAM Cairo is our house line — formulas drawn from Nile botanicals, desert oils and the quiet discipline of a hammam morning. Every bottle is mixed in small batches in Cairo.'],
            ['slug' => 'arganic', 'name' => 'Arganic', 'name_ar' => 'أرقانيك', 'origin' => 'MA', 'story' => "Women's cooperatives in the Souss-Massa press argan by hand. Arganic bottles that oil unrefined, unfragranced, and honest."],
            ['slug' => 'natura-maroc', 'name' => 'Natura Maroc', 'name_ar' => 'ناتورا ماروك', 'origin' => 'MA', 'story' => 'Rhassoul from the Middle Atlas, cedar from the high cedar forests, orange blossom from the plains of Khemisset.'],
            ['slug' => 'zahra-beauty', 'name' => 'Zahra Beauty', 'name_ar' => 'زهرة بيوتي', 'origin' => 'EG', 'story' => 'Zahra works with Delta farms for neroli, lotus and date blossom — florals that have scented Egyptian mornings for centuries.'],
            ['slug' => 'oud-and-more', 'name' => 'Oud & More', 'name_ar' => 'عود آند مور', 'origin' => 'AE', 'story' => 'A perfume house in Dubai composing attars from Cambodian oud, Taif rose and frankincense — worn close to the skin.'],
            ['slug' => 'atlas-rituals', 'name' => 'Atlas Rituals', 'name_ar' => 'أطلس ريتشوالز', 'origin' => 'MA', 'story' => 'Atlas Rituals restores the hammam sequence — black soap, kessa, rhassoul, argan — with the materials village ateliers still make.'],
            ['slug' => 'noura-botanics', 'name' => 'Noura Botanics', 'name_ar' => 'نورة بوتانيكس', 'origin' => 'EG', 'story' => 'Named for a grandmother who distilled rose in Fayoum. Noura bottles Damask rose, shea and cold-pressed oils for skin that lives in the sun.'],
            ['slug' => 'sahara-roots', 'name' => 'Sahara Roots', 'name_ar' => 'صحارى روتس', 'origin' => 'MA', 'story' => 'Hair formulas built on cactus, argan and prickly pear — plants that keep their water through the longest dry season.'],
            ['slug' => 'cairo-scents', 'name' => 'Cairo Scents', 'name_ar' => 'كايرو سنتس', 'origin' => 'EG', 'story' => 'A small atelier in Garden City composing the smell of Cairo: amber, fig leaf, jasmine after dusk, hot stone after prayer.'],
            ['slug' => 'elixir-wellness', 'name' => 'Elixir Wellness', 'name_ar' => 'إكسير ويلنس', 'origin' => 'LB', 'story' => 'Saffron, sage and orange blossom from the Bekaa, prepared as teas, drops and balms for nervous systems that live too loudly.'],
        ];

        $brandIds = [];
        foreach ($brands as $b) {
            $row = Brand::updateOrCreate(
                ['slug' => $b['slug']],
                [
                    'name' => $b['name'],
                    'name_ar' => $b['name_ar'],
                    'origin_country' => $b['origin'],
                    'description' => $b['story'],
                    'description_ar' => null,
                    'logo_url' => null,
                    'is_active' => true,
                ]
            );
            $brandIds[$b['slug']] = $row->id;
        }

        $ingredients = [
            ['slug' => 'argan', 'name' => 'Argan', 'name_ar' => 'الأرغان', 'description' => 'Pressed from the kernels of the argan tree. A dry, golden oil that feeds skin and hair without a heavy film.', 'description_ar' => 'يُعصر من نوى شجرة الأرغان. زيت ذهبي خفيف يغذي البشرة والشعر دون ثقل.'],
            ['slug' => 'rose', 'name' => 'Damask Rose', 'name_ar' => 'ورد دمشق', 'description' => 'Petals distilled at dawn. Softens, calms redness, and carries the quiet of a garden before heat.', 'description_ar' => 'بتلات تُقطَّر عند الفجر. تلطّف الاحمرار وتحمل هدوء الحديقة قبل الحر.'],
            ['slug' => 'neroli', 'name' => 'Neroli', 'name_ar' => 'النيرولي', 'description' => 'The blossom of the bitter orange. Bright, floral, a tonic for dull or tight skin.', 'description_ar' => 'زهرة النارنج المر. نبرة زهرية مشرقة تنعش البشرة الباهتة.'],
            ['slug' => 'oud', 'name' => 'Oud', 'name_ar' => 'العود', 'description' => 'Resin of aquilaria, smoky and animalic, the spine of Gulf perfumery.', 'description_ar' => 'راتنج العود، دخاني وحيوي، عمود عطور الخليج.'],
            ['slug' => 'black-soap', 'name' => 'Beldi Black Soap', 'name_ar' => 'الصابون البلدي', 'description' => 'Olive paste cooked with eucalyptus. The first step of the hammam: it softens, then the kessa lifts.', 'description_ar' => 'معجون زيتون يُطبخ مع الكافور. الخطوة الأولى في الحمّام.'],
            ['slug' => 'prickly-pear', 'name' => 'Prickly Pear', 'name_ar' => 'التين الشوكي', 'description' => 'Seed oil from the cactus fruit. Exceptionally high in vitamin E — a few drops for the face at night.', 'description_ar' => 'زيت بذور التين الشوكي، غني بفيتامين E — قطرات للوجه ليلاً.'],
            ['slug' => 'rhassoul', 'name' => 'Rhassoul Clay', 'name_ar' => 'الغاسول', 'description' => 'A mineral clay that washes without stripping. Used on hair, face and body in the hammam.', 'description_ar' => 'طين معدني ينظّف دون أن يجفف. يُستخدم للشعر والبشرة في الحمّام.'],
            ['slug' => 'saffron', 'name' => 'Saffron', 'name_ar' => 'الزعفران', 'description' => 'Threads of crocus for glow, mood and a tea that slows the afternoon.', 'description_ar' => 'خيوط الزعفران للإشراق والمزاج وشاي يبطئ العصر.'],
        ];

        $ingredientIds = [];
        foreach ($ingredients as $ing) {
            $row = Ingredient::updateOrCreate(
                ['slug' => $ing['slug']],
                [
                    'name' => $ing['name'],
                    'name_ar' => $ing['name_ar'],
                    'description' => $ing['description'],
                    'description_ar' => $ing['description_ar'],
                    'is_active' => true,
                ]
            );
            $ingredientIds[$ing['slug']] = $row->id;
        }

        $rituals = [
            ['slug' => 'hammam-morning', 'title' => 'Hammam morning', 'title_ar' => 'صباح الحمّام', 'description' => 'Steam, black soap, kessa, clay, oil — the classic sequence without hurry.', 'description_ar' => 'بخار، صابون أسود، كيسة، طين، زيت — التسلسل الكلاسيكي بلا عجلة.', 'image_url' => '/images/products/black-soap.jpg', 'sort' => 1],
            ['slug' => 'evening-oils', 'title' => 'Evening oils', 'title_ar' => 'زيوت المساء', 'description' => 'A quiet night ritual of mist, face oil and a single drop of attar.', 'description_ar' => 'طقس ليلي هادئ من الرذاذ وزيت الوجه وقطرة عطر.', 'image_url' => '/images/products/serum.jpg', 'sort' => 2],
            ['slug' => 'weekly-glow', 'title' => 'Weekly glow', 'title_ar' => 'توهج أسبوعي', 'description' => 'Scrub, mask and rose water for skin that has lived through the city week.', 'description_ar' => 'مقشر وقناع وماء ورد للبشرة بعد أسبوع المدينة.', 'image_url' => '/images/products/rose-scrub.jpg', 'sort' => 3],
            ['slug' => 'calm-hour', 'title' => 'The calm hour', 'title_ar' => 'ساعة الهدوء', 'description' => 'Sage tea, saffron drops, fig & amber candle — soft restoratives.', 'description_ar' => 'شاي المريمية وقطرات الزعفران وشمعة التين والعنبر.', 'image_url' => '/images/products/tea.jpg', 'sort' => 4],
        ];

        $ritualIds = [];
        foreach ($rituals as $r) {
            $row = Ritual::updateOrCreate(
                ['slug' => $r['slug']],
                [
                    'title' => $r['title'],
                    'title_ar' => $r['title_ar'],
                    'description' => $r['description'],
                    'description_ar' => $r['description_ar'],
                    'image_url' => $this->assetUrl($r['image_url']),
                    'is_published' => true,
                    'sort_order' => $r['sort'],
                ]
            );
            $ritualIds[$r['slug']] = $row->id;
        }

        $ritualTagMap = [
            'Hammam' => 'hammam-morning',
            'Purify' => 'hammam-morning',
            'Evening' => 'evening-oils',
            'Hydrate' => 'evening-oils',
            'Glow' => 'weekly-glow',
            'Brighten' => 'weekly-glow',
            'Anti-age' => 'weekly-glow',
            'Calm' => 'calm-hour',
        ];

        $ritualProductMap = [];
        $products = $this->products();

        foreach ($products as $p) {
            $product = Product::updateOrCreate(
                ['slug' => $p['slug']],
                [
                    'brand_id' => $brandIds[$p['brand']] ?? null,
                    'category_id' => $categoryIds[$p['category']] ?? null,
                    'name' => $p['name'],
                    'name_ar' => $p['nameAr'],
                    'short_description' => Str::limit($p['description'], 160, ''),
                    'short_description_ar' => Str::limit($p['descriptionAr'], 160, ''),
                    'description' => $p['description'],
                    'description_ar' => $p['descriptionAr'],
                    'ingredients' => implode("\n", $p['ingredients']),
                    'ingredients_ar' => null,
                    'usage_instructions' => $p['howTo'],
                    'usage_instructions_ar' => null,
                    'status' => ProductStatus::Active,
                    'track_inventory' => true,
                    'seo_title' => $p['name'] . ' | KHAM Cairo',
                    'seo_title_ar' => $p['nameAr'] . ' | خام القاهرة',
                    'seo_description' => Str::limit($p['description'], 155, ''),
                    'seo_description_ar' => Str::limit($p['descriptionAr'], 155, ''),
                    'metadata' => [
                        'bestseller' => $p['bestseller'] ?? false,
                        'is_new' => $p['isNew'] ?? false,
                        'rating' => $p['rating'],
                        'reviews' => $p['reviews'],
                        'ingredient_slugs' => $p['ingredientSlugs'],
                        'ritual' => $p['ritual'],
                        'size' => $p['size'],
                    ],
                ]
            );

            ProductMedia::query()->where('product_id', $product->id)->delete();
            ProductMedia::create([
                'product_id' => $product->id,
                'type' => 'image',
                'url' => $this->assetUrl($p['image']),
                'alt_text' => $p['name'],
                'sort_order' => 0,
            ]);
            if (!empty($p['hoverImage'])) {
                ProductMedia::create([
                    'product_id' => $product->id,
                    'type' => 'image',
                    'url' => $this->assetUrl($p['hoverImage']),
                    'alt_text' => $p['name'] . ' alternate',
                    'sort_order' => 1,
                ]);
            }

            $sku = 'KHAM-' . strtoupper(Str::slug($p['slug'], '-'));
            $variant = ProductVariant::updateOrCreate(
                ['sku' => $sku],
                [
                    'product_id' => $product->id,
                    'title' => $p['size'],
                    'option_values' => ['size' => $p['size']],
                    'weight_grams' => null,
                    'cost_price' => round($p['priceEgp'] * 0.45, 2),
                    'cost_currency_code' => 'EGP',
                    'is_active' => true,
                ]
            );

            ProductMarketPrice::updateOrCreate(
                [
                    'variant_id' => $variant->id,
                    'market_id' => $market->id,
                ],
                [
                    'currency_id' => $currencyId,
                    'price' => $p['priceEgp'],
                    'compare_at_price' => $p['compareAtEgp'] ?? null,
                    'starts_at' => null,
                    'ends_at' => null,
                    'is_active' => true,
                ]
            );

            InventoryStock::updateOrCreate(
                [
                    'variant_id' => $variant->id,
                    'warehouse_id' => $warehouse->id,
                ],
                [
                    'on_hand' => 50,
                    'reserved' => 0,
                    'damaged' => 0,
                    'reorder_level' => 10,
                ]
            );

            $syncIngredients = [];
            foreach (array_values($p['ingredientSlugs']) as $sort => $slug) {
                if (isset($ingredientIds[$slug])) {
                    $syncIngredients[$ingredientIds[$slug]] = ['sort_order' => $sort];
                }
            }
            $product->linkedIngredients()->sync($syncIngredients);

            foreach ($p['ritual'] as $tag) {
                $ritualSlug = $ritualTagMap[$tag] ?? null;
                if ($ritualSlug && isset($ritualIds[$ritualSlug])) {
                    $ritualProductMap[$ritualSlug][$product->id] = true;
                }
            }
        }

        foreach ($ritualProductMap as $ritualSlug => $productIdMap) {
            $sort = 0;
            $sync = [];
            foreach (array_keys($productIdMap) as $productId) {
                $sync[$productId] = ['sort_order' => $sort++];
            }
            Ritual::query()->find($ritualIds[$ritualSlug])?->products()->sync($sync);
        }
    }

    /** @return list<array<string, mixed>> */
    private function products(): array
    {
        return [
            [
                'slug' => 'pure-argan-oil', 'name' => 'Pure Argan Oil', 'nameAr' => 'زيت الأرغان النقي',
                'brand' => 'atlas-rituals', 'category' => 'haircare', 'priceEgp' => 1250,
                'rating' => 5, 'reviews' => 124, 'image' => '/images/products/argan-oil.jpg',
                'bestseller' => true, 'size' => '50 ml',
                'description' => "Cold-pressed argan from women's cooperatives in the Souss. A dry oil for ends, cuticles and the last step of a night ritual. Nothing added — no fragrance, no filler.",
                'descriptionAr' => 'زيت أرغان معصور على البارد من تعاونيات نسائية في سوس. زيت جاف لأطراف الشعر ولآخر خطوة في طقس الليل.',
                'howTo' => 'Warm three drops between palms. Press through damp hair, or tap over serum on the face at night.',
                'ingredients' => ['Argania spinosa kernel oil 100%'], 'ingredientSlugs' => ['argan'], 'ritual' => ['Glow', 'Hydrate'],
            ],
            [
                'slug' => 'moroccan-black-soap', 'name' => 'Moroccan Black Soap', 'nameAr' => 'الصابون المغربي الأسود',
                'brand' => 'kham-cairo', 'category' => 'hammam', 'priceEgp' => 690,
                'rating' => 5, 'reviews' => 98, 'image' => '/images/products/black-soap.jpg',
                'bestseller' => true, 'size' => '200 g',
                'description' => 'Olive paste cooked slowly with eucalyptus. Spread over steam-warmed skin, rest, then lift with a kessa glove. The classic opening of a hammam.',
                'descriptionAr' => 'معجون زيتون يُطبخ ببطء مع الكافور. يُدهن على بشرة دافئة ثم يُرفع بالقفاز.',
                'howTo' => 'After a warm shower, apply a thin layer. Leave three minutes. Exfoliate with the kessa from limbs toward the heart.',
                'ingredients' => ['Olea europaea fruit', 'Eucalyptus globulus', 'Potassium hydroxide'], 'ingredientSlugs' => ['black-soap'], 'ritual' => ['Purify', 'Hammam'],
            ],
            [
                'slug' => 'rose-body-scrub', 'name' => 'Rose Body Scrub', 'nameAr' => 'مقشر الورد للجسم',
                'brand' => 'noura-botanics', 'category' => 'bodycare', 'priceEgp' => 980,
                'rating' => 5, 'reviews' => 76, 'image' => '/images/products/rose-scrub.jpg',
                'bestseller' => true, 'size' => '250 g',
                'description' => 'Fine sugar, Damask rose water and cold-pressed almond. A weekly polish that leaves skin scented like a Fayoum garden after rain.',
                'descriptionAr' => 'سكر ناعم وماء ورد دمشق واللوز. تلميع أسبوعي يترك البشرة معطرة.',
                'howTo' => 'On damp skin, massage in circles. Rinse. Follow with body oil while the skin is still wet.',
                'ingredients' => ['Cane sugar', 'Rosa damascena water', 'Prunus amygdalus oil', 'Shea butter'], 'ingredientSlugs' => ['rose'], 'ritual' => ['Glow', 'Hydrate'],
            ],
            [
                'slug' => 'neroli-face-mist', 'name' => 'Neroli Face Mist', 'nameAr' => 'رذاذ النيرولي للوجه',
                'brand' => 'zahra-beauty', 'category' => 'skincare', 'priceEgp' => 720,
                'rating' => 5, 'reviews' => 54, 'image' => '/images/products/neroli-mist.jpg',
                'bestseller' => true, 'isNew' => true, 'size' => '100 ml',
                'description' => 'Hydrosol of bitter-orange blossom from the Delta. A bright, cooling mist to set makeup, wake a desk-tired face, or seal oil.',
                'descriptionAr' => 'ماء زهرة النارنج من الدلتا. رذاذ منعش يثبّت الزيت ويوقظ الوجه.',
                'howTo' => "Shake. Mist from an arm's length, eyes closed. Use morning, afternoon, and over the last oil of the night.",
                'ingredients' => ['Citrus aurantium amara flower water', 'Glycerin', 'Sodium PCA'], 'ingredientSlugs' => ['neroli'], 'ritual' => ['Glow', 'Brighten'],
            ],
            [
                'slug' => 'amber-no-7', 'name' => 'Amber No. 7', 'nameAr' => 'عنبر رقم ٧',
                'brand' => 'cairo-scents', 'category' => 'fragrance', 'priceEgp' => 1600,
                'rating' => 5, 'reviews' => 54, 'image' => '/images/products/amber-perfume.jpg',
                'bestseller' => true, 'size' => '50 ml',
                'description' => 'A Cairo evening in a square bottle: labdanum, fig leaf, a ribbon of jasmine, warm stone. Soft, not sweet. Made for skin, not a room.',
                'descriptionAr' => 'مساء قاهري في زجاجة مربعة: لابدانوم، ورق تين، ياسمين، حجر دافئ.',
                'howTo' => 'One spray at the throat, one at the wrist. Do not rub. Let the heat of the body open it.',
                'ingredients' => ['Alcohol', 'Parfum', 'Labdanum', 'Fig leaf', 'Jasmine sambac', 'Benzoin'], 'ingredientSlugs' => ['oud'], 'ritual' => ['Evening'],
            ],
            [
                'slug' => 'repair-hair-mask', 'name' => 'Repair Hair Mask', 'nameAr' => 'ماسك إصلاح الشعر',
                'brand' => 'sahara-roots', 'category' => 'haircare', 'priceEgp' => 840,
                'rating' => 5, 'reviews' => 67, 'image' => '/images/products/hair-mask.jpg',
                'bestseller' => true, 'size' => '200 ml',
                'description' => 'A weekly mask of cactus, argan and protein for hair that has lived through heat, colour and the khamseen. Softens without flattening.',
                'descriptionAr' => 'ماسك أسبوعي من الصبار والأرغان للشعر الذي عاش الحر واللون.',
                'howTo' => 'After shampoo, apply from mid-lengths to ends. Leave ten minutes under a warm towel. Rinse cool.',
                'ingredients' => ['Cactus extract', 'Argan oil', 'Hydrolyzed wheat protein', 'Shea'], 'ingredientSlugs' => ['argan', 'prickly-pear'], 'ritual' => ['Hydrate'],
            ],
            [
                'slug' => 'prickly-pear-face-oil', 'name' => 'Prickly Pear Face Oil', 'nameAr' => 'زيت التين الشوكي للوجه',
                'brand' => 'kham-cairo', 'category' => 'skincare', 'priceEgp' => 1450,
                'rating' => 5, 'reviews' => 41, 'image' => '/images/products/serum.jpg',
                'isNew' => true, 'size' => '30 ml',
                'description' => 'A rare seed oil, pale and quick. High in vitamin E and sterols — the night oil we reach for when the desert air has taken too much.',
                'descriptionAr' => 'زيت بذور نادر، خفيف وسريع. غني بفيتامين E لليل الصحراوي.',
                'howTo' => 'After mist and serum, press four drops over face and décolleté. Use at night; a single drop under the eye is enough.',
                'ingredients' => ['Opuntia ficus-indica seed oil', 'Vitamin E'], 'ingredientSlugs' => ['prickly-pear'], 'ritual' => ['Glow', 'Anti-age'],
            ],
            [
                'slug' => 'argan-body-oil', 'name' => 'Argan Body Oil', 'nameAr' => 'زيت الأرغان للجسم',
                'brand' => 'arganic', 'category' => 'bodycare', 'priceEgp' => 890,
                'rating' => 4.8, 'reviews' => 88, 'image' => '/images/products/body-oil.jpg',
                'size' => '100 ml',
                'description' => 'The same unrefined argan, in a bottle meant for limbs. Absorbs in a minute. The smell is nut, nothing else.',
                'descriptionAr' => 'أرغان غير مكرّر للجسم. يُمتص خلال دقيقة ورائحته المكسرات فقط.',
                'howTo' => 'Apply to damp skin after bathing, paying attention to elbows, knees and the dry line of the shins.',
                'ingredients' => ['Argania spinosa kernel oil 100%'], 'ingredientSlugs' => ['argan'], 'ritual' => ['Hydrate'],
            ],
            [
                'slug' => 'rhassoul-clay-mask', 'name' => 'Rhassoul Clay Mask', 'nameAr' => 'قناع طين الغاسول',
                'brand' => 'natura-maroc', 'category' => 'hammam', 'priceEgp' => 420,
                'rating' => 4.9, 'reviews' => 63, 'image' => '/images/products/clay.jpg',
                'size' => '150 g',
                'description' => 'Mineral clay from the Middle Atlas. Mixed with rose or orange-blossom water it becomes a mask for face, scalp and body that cleanses without the tight aftermath of foam.',
                'descriptionAr' => 'طين معدني من الأطلس المتوسط. يُخلط بماء الورد لقناع ينظّف دون شد.',
                'howTo' => 'Mix two spoons of clay with hydrosol to a cream. Apply. Rest ten minutes. Rinse. Follow with oil.',
                'ingredients' => ['Moroccan lava clay (rhassoul)'], 'ingredientSlugs' => ['rhassoul', 'rose'], 'ritual' => ['Purify'],
            ],
            [
                'slug' => 'date-blossom-night-cream', 'name' => 'Date Blossom Night Cream', 'nameAr' => 'كريم الليل بزهر النخيل',
                'brand' => 'zahra-beauty', 'category' => 'skincare', 'priceEgp' => 1180,
                'rating' => 4.9, 'reviews' => 39, 'image' => '/images/products/night-cream.jpg',
                'isNew' => true, 'size' => '50 ml',
                'description' => "A cushioned cream of date blossom, jojoba and shea. For skin that wants comfort more than a serum's sting. Wakes as skin, not as product.",
                'descriptionAr' => 'كريم مبطّن من زهر النخيل والجوجوبا والشيا. راحة للبشرة ليلاً.',
                'howTo' => 'After oil, a pearl of cream over the face. Press, do not drag. Use nightly.',
                'ingredients' => ['Phoenix dactylifera flower extract', 'Jojoba', 'Shea', 'Squalane'], 'ingredientSlugs' => ['neroli'], 'ritual' => ['Hydrate', 'Anti-age'],
            ],
            [
                'slug' => 'royal-oud-attar', 'name' => 'Royal Oud Attar', 'nameAr' => 'عطر العود الملكي',
                'brand' => 'oud-and-more', 'category' => 'fragrance', 'priceEgp' => 2400,
                'rating' => 5, 'reviews' => 31, 'image' => '/images/products/oud.jpg',
                'size' => '12 ml',
                'description' => 'An oil attar, not an eau. Cambodian oud, Taif rose, a thread of ambergris accord. One drop at the pulse is a whole evening.',
                'descriptionAr' => 'عطر زيتي لا أو دو بارفان. عود كمبودي وورد الطائف.',
                'howTo' => 'A single drop at the throat or behind the ear. Oil attars bloom slowly — wait before adding more.',
                'ingredients' => ['Santalum', 'Aquilaria oil', 'Rosa damascena', 'Ambergris accord'], 'ingredientSlugs' => ['oud', 'rose'], 'ritual' => ['Evening'],
            ],
            [
                'slug' => 'saffron-calm-drops', 'name' => 'Saffron Calm Drops', 'nameAr' => 'قطرات الزعفران للهدوء',
                'brand' => 'elixir-wellness', 'category' => 'wellness', 'priceEgp' => 760,
                'rating' => 4.7, 'reviews' => 44, 'image' => '/images/products/serum.jpg', 'hoverImage' => '/images/olive.jpg',
                'size' => '30 ml',
                'description' => 'Saffron and orange blossom in a vegetable glycerin base. A dropper on the tongue before the hour that frays.',
                'descriptionAr' => 'زعفران وزهر البرتقال في غليسيرين. قطارة على اللسان قبل ساعة التوتر.',
                'howTo' => 'Shake. One dropper under the tongue, late afternoon or before sleep. Not a sedative — a softening.',
                'ingredients' => ['Crocus sativus', 'Citrus aurantium blossom', 'Vegetable glycerin'], 'ingredientSlugs' => ['saffron', 'neroli'], 'ritual' => ['Calm'],
            ],
            [
                'slug' => 'fig-amber-candle', 'name' => 'Fig & Amber Candle', 'nameAr' => 'شمعة التين والعنبر',
                'brand' => 'cairo-scents', 'category' => 'home', 'priceEgp' => 540,
                'rating' => 4.8, 'reviews' => 52, 'image' => '/images/products/candle.jpg',
                'size' => '220 g',
                'description' => 'A wooden-wick candle of fig leaf, amber and a little smoke. The smell of a Garden City balcony at dusk.',
                'descriptionAr' => 'شمعة بفتيل خشبي من ورق التين والعنبر. رائحة شرفة في جاردن سيتي عند الغروب.',
                'howTo' => 'First burn: let the melt pool reach the glass. Trim the wood wick to 5 mm. Never more than three hours.',
                'ingredients' => ['Soy wax', 'Fig leaf accord', 'Amber', 'Wooden wick'], 'ingredientSlugs' => ['oud'], 'ritual' => ['Calm', 'Evening'],
            ],
            [
                'slug' => 'traditional-kessa-glove', 'name' => 'Traditional Kessa Glove', 'nameAr' => 'قفاز الكيسة التقليدي',
                'brand' => 'atlas-rituals', 'category' => 'hammam', 'priceEgp' => 180,
                'rating' => 4.9, 'reviews' => 110, 'image' => '/images/products/kessa.jpg',
                'size' => 'One size',
                'description' => "A coarse plant-fibre glove, the partner of black soap. Used weekly it keeps the skin's surface honest.",
                'descriptionAr' => 'قفاز من ألياف نباتية خشنة، شريك الصابون الأسود.',
                'howTo' => 'Soak. After black soap has rested, sweep firmly toward the heart. Rinse the glove, hang to dry.',
                'ingredients' => ['Plant fibre'], 'ingredientSlugs' => ['black-soap'], 'ritual' => ['Purify', 'Hammam'],
            ],
            [
                'slug' => 'damask-rose-water', 'name' => 'Damask Rose Water', 'nameAr' => 'ماء ورد دمشق',
                'brand' => 'noura-botanics', 'category' => 'skincare', 'priceEgp' => 390,
                'rating' => 4.8, 'reviews' => 91, 'image' => '/images/products/neroli-mist.jpg',
                'size' => '200 ml',
                'description' => 'A true hydrosol, distilled once. No rose oil spiked in after. Use as a toner, a clay mixer, a linen mist.',
                'descriptionAr' => 'ماء ورد مقطر مرة واحدة. يُستخدم تونرًا وخلطًا للطين.',
                'howTo' => 'Saturate a cotton pad and press over the face, or mist freely. Store away from sun.',
                'ingredients' => ['Rosa damascena flower water'], 'ingredientSlugs' => ['rose'], 'ritual' => ['Calm', 'Hydrate'],
            ],
            [
                'slug' => 'cactus-hair-oil', 'name' => 'Cactus Hair Oil', 'nameAr' => 'زيت الصبار للشعر',
                'brand' => 'sahara-roots', 'category' => 'haircare', 'priceEgp' => 720,
                'rating' => 4.7, 'reviews' => 48, 'image' => '/images/products/argan-oil.jpg', 'hoverImage' => '/images/hair.jpg',
                'size' => '50 ml',
                'description' => 'Prickly-pear and argan for hair that breaks at the ends. A few drops on a dry day, or as a pre-wash on a Sunday.',
                'descriptionAr' => 'تين شوكي وأرغان للشعر الذي ينكسر عند الأطراف.',
                'howTo' => 'Warm two drops and press through ends. For a pre-wash, coat the lengths and rest thirty minutes.',
                'ingredients' => ['Opuntia seed oil', 'Argan oil', 'Jojoba'], 'ingredientSlugs' => ['prickly-pear', 'argan'], 'ritual' => ['Hydrate'],
            ],
            [
                'slug' => 'cairo-ritual-set', 'name' => 'The Cairo Ritual Set', 'nameAr' => 'طقم طقوس القاهرة',
                'brand' => 'kham-cairo', 'category' => 'gifts', 'priceEgp' => 3200, 'compareAtEgp' => 3650,
                'rating' => 5, 'reviews' => 22, 'image' => '/images/products/gift-set.jpg',
                'isNew' => true, 'size' => 'Set of 3',
                'description' => 'Black soap, prickly-pear face oil, and a kessa glove, nested in linen. The house introduction to a KHAM morning.',
                'descriptionAr' => 'صابون أسود وزيت تين شوكي وقفاز كيسة في كتان. مقدمة لبيت خام.',
                'howTo' => 'Begin with steam and black soap. Finish the face with four drops of oil. The glove lives by the bath.',
                'ingredients' => ['See individual products'], 'ingredientSlugs' => ['black-soap', 'prickly-pear'], 'ritual' => ['Hammam', 'Glow'],
            ],
            [
                'slug' => 'honey-argan-soap', 'name' => 'Honey Argan Soap', 'nameAr' => 'صابون العسل والأرغان',
                'brand' => 'arganic', 'category' => 'bodycare', 'priceEgp' => 220,
                'rating' => 4.6, 'reviews' => 74, 'image' => '/images/products/soap.jpg',
                'size' => '120 g',
                'description' => 'A poured bar of olive, argan and mountain honey. Daily, gentle, the opposite of a detergent.',
                'descriptionAr' => 'قالب من الزيتون والأرغان وعسل الجبل. لطيف للاستخدام اليومي.',
                'howTo' => 'Work to a thin lather between wet hands, then over the body. Keep the bar dry between uses.',
                'ingredients' => ['Olive oil', 'Argan oil', 'Honey', 'Coconut oil'], 'ingredientSlugs' => ['argan'], 'ritual' => ['Purify'],
            ],
            [
                'slug' => 'desert-sage-tea', 'name' => 'Desert Sage Tea', 'nameAr' => 'شاي المريمية الصحراوية',
                'brand' => 'elixir-wellness', 'category' => 'wellness', 'priceEgp' => 280,
                'rating' => 4.8, 'reviews' => 57, 'image' => '/images/products/tea.jpg',
                'size' => '40 g',
                'description' => 'A loose tea of sage, lemon verbena and a few saffron threads. The cup after a long day, or the pause before a hammam.',
                'descriptionAr' => 'شاي سائب من المريمية واللويزة وخيوط زعفران.',
                'howTo' => 'A teaspoon per cup, just-off-boil water, six minutes covered. No milk.',
                'ingredients' => ['Sage', 'Lemon verbena', 'Saffron'], 'ingredientSlugs' => ['saffron'], 'ritual' => ['Calm'],
            ],
            [
                'slug' => 'atlas-cedar-incense', 'name' => 'Atlas Cedar Incense', 'nameAr' => 'بخور أرز الأطلس',
                'brand' => 'natura-maroc', 'category' => 'home', 'priceEgp' => 310,
                'rating' => 4.7, 'reviews' => 36, 'image' => '/images/products/candle.jpg',
                'size' => '20 sticks',
                'description' => 'Cedarwood from the high Atlas, rolled by hand. A dry, resinous smoke for cupboards, entryways, the hour after guests.',
                'descriptionAr' => 'خشب أرز من الأطلس العالي، ملفوف يدويًا. دخان جاف للمداخل.',
                'howTo' => 'Light, let the tip glow, blow out. Rest in a heat-safe dish. Open a window if the room is small.',
                'ingredients' => ['Cedrus atlantica wood', 'Natural binder'], 'ingredientSlugs' => ['oud'], 'ritual' => ['Evening'],
            ],
            [
                'slug' => 'bakhoor-oud-chips', 'name' => 'Bakhoor Oud Chips', 'nameAr' => 'خشب عود البخور',
                'brand' => 'oud-and-more', 'category' => 'home', 'priceEgp' => 480,
                'rating' => 5, 'reviews' => 29, 'image' => '/images/products/oud.jpg',
                'size' => '25 g',
                'description' => 'Small chips of oud-soaked wood for a traditional mabkhara. The house fills, then the clothes, then the memory of the visit.',
                'descriptionAr' => 'رقائق خشب مشبعة بالعود للمبخرة التقليدية.',
                'howTo' => 'A charcoal disc, then one chip. Never leave unattended. A single chip scents a room for an hour.',
                'ingredients' => ['Agarwood chips', 'Oud oil'], 'ingredientSlugs' => ['oud'], 'ritual' => ['Evening'],
            ],
            [
                'slug' => 'lotus-glow-serum', 'name' => 'Lotus Glow Serum', 'nameAr' => 'سيروم توهج اللوتس',
                'brand' => 'zahra-beauty', 'category' => 'skincare', 'priceEgp' => 1340,
                'rating' => 4.9, 'reviews' => 45, 'image' => '/images/products/serum.jpg',
                'isNew' => true, 'size' => '30 ml',
                'description' => 'Nile lotus, niacinamide and a light hyaluronic. The morning serum when the skin looks tired of the city.',
                'descriptionAr' => 'لوتس النيل ونياسيناميد وحمض الهيالورونيك. سيروم الصباح.',
                'howTo' => 'On clean, misted skin, three drops. Follow with cream or oil. Use morning and night.',
                'ingredients' => ['Nelumbo nucifera extract', 'Niacinamide 5%', 'Hyaluronic acid', 'Glycerin'], 'ingredientSlugs' => ['neroli'], 'ritual' => ['Glow', 'Brighten'],
            ],
            [
                'slug' => 'black-soap-paste', 'name' => 'Black Soap Paste', 'nameAr' => 'معجون الصابون الأسود',
                'brand' => 'atlas-rituals', 'category' => 'hammam', 'priceEgp' => 350,
                'rating' => 4.8, 'reviews' => 81, 'image' => '/images/products/black-soap.jpg',
                'size' => '250 g',
                'description' => 'A looser, kitchen-style beldi soap — the one used in Marrakech hammams. Eucalyptus-forward, unsparing, honest.',
                'descriptionAr' => 'صابون بلدي رخو كما في حمّامات مراكش. برائحة الكافور.',
                'howTo' => 'Scoop with a dry spoon. Spread. Rest. Kessa. The paste is concentrated — a little covers the body.',
                'ingredients' => ['Olive', 'Eucalyptus', 'Potassium soap'], 'ingredientSlugs' => ['black-soap'], 'ritual' => ['Purify', 'Hammam'],
            ],
            [
                'slug' => 'rose-shea-body-butter', 'name' => 'Rose & Shea Body Butter', 'nameAr' => 'زبدة الجسم بالورد والشيا',
                'brand' => 'noura-botanics', 'category' => 'bodycare', 'priceEgp' => 640,
                'rating' => 4.9, 'reviews' => 58, 'image' => '/images/products/rose-scrub.jpg',
                'size' => '180 g',
                'description' => 'Whipped shea with Damask rose. For shins, elbows, the winter, the air-conditioned office. A little warms to oil.',
                'descriptionAr' => 'شيا مخفوقة مع ورد دمشق. للكوعين والساقين والشتاء.',
                'howTo' => 'A cold-cream amount, warmed between palms, pressed into dry areas after bathing.',
                'ingredients' => ['Butyrospermum parkii', 'Rosa damascena', 'Jojoba', 'Vitamin E'], 'ingredientSlugs' => ['rose'], 'ritual' => ['Hydrate'],
            ],
        ];
    }
}

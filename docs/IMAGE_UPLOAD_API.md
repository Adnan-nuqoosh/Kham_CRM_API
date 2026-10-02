# Catalog Image Upload API

Image uploads are now supported for Categories, Brands, and Products.

The existing database already has:
- `categories.image_url`
- `brands.logo_url`
- `product_media`

No migration is required.

## Server setup

After pulling this change:

```bash
php artisan storage:link
php artisan optimize:clear
php artisan route:list
```

If Laravel reports that `public/storage` already exists, that is fine.

All endpoints require:
- Sanctum Bearer token
- `products.manage` permission

## Category

Upload/replace image:

```http
POST /api/v1/categories/{category}/image
Content-Type: multipart/form-data
```

Field:
- `image`: JPG/JPEG/PNG/WEBP, max 5 MB

Delete:
```http
DELETE /api/v1/categories/{category}/image
```

## Brand

Upload/replace logo:

```http
POST /api/v1/brands/{brand}/image
Content-Type: multipart/form-data
```

Field:
- `image`: JPG/JPEG/PNG/WEBP, max 5 MB

Delete:
```http
DELETE /api/v1/brands/{brand}/image
```

## Product

Upload one or more product images:

```http
POST /api/v1/products/{product}/images
Content-Type: multipart/form-data
```

Fields:
- `images[]`: repeat for multiple files, max 10
- `alt_texts[]`: optional, same order as images
- `is_primary`: optional boolean; if true, first uploaded image gets sort order 0

Each product image may be JPG/JPEG/PNG/WEBP up to 8 MB.

Delete one product image:

```http
DELETE /api/v1/products/{product}/images/{media}
```

## Recommended frontend flow

Keep entity creation and binary file upload separate:

1. Create Category / Brand / Product using the existing endpoint.
2. Take the returned ID.
3. Upload image(s) using the new multipart endpoint.

The user still sees one normal Add/Edit form in the frontend; the frontend performs the second upload request automatically after the entity is created.

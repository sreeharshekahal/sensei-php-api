# Cloudflare R2 Configuration for Staging

This guide documents how to configure **Cloudflare R2** (S3-compatible object storage) for the `sensei-php-api` staging environment.

---

## Why the `region` Error Occurs

The API currently uses Laravel's `s3` filesystem disk in:
- `app/Http/Controllers/AttachmentController.php` (`Storage::disk('s3')->putFileAs(...)` and `Storage::disk('s3')->url(...)`)
- `app/Http/Controllers/EvidenceController.php` (`Storage::disk('s3')->url(...)`)
- `app/Http/Controllers/ObservationStatsController.php` (`Storage::disk('s3')->url(...)`)

If `AWS_DEFAULT_REGION` is missing in staging, `POST /api/attachments` fails with `500`:

```text
S3 upload failed: Missing required client configuration options:
region: (string)
A "region" configuration value is required for the "s3" service
```

Additionally, in `config/filesystems.php`, `url` and `endpoint` are currently commented out on the `s3` disk, which prevents Laravel from routing requests to Cloudflare R2 and generating public R2 URLs.

---

## 1. Update `config/filesystems.php`

In `config/filesystems.php`, uncomment `url` and `endpoint` in the `s3` disk configuration (and optionally default `region` to `'auto'`):

```php
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
            'report' => false,
        ],
```

> **Note on `aws/aws-sdk-php` (`^3.356`):**  
> Newer versions of `aws/aws-sdk-php` enable flexible checksums by default. If you encounter checksum or signature errors against R2, add the following to your staging environment variables (or `s3` disk config):
> ```dotenv
> AWS_REQUEST_CHECKSUM_CALCULATION=when_required
> AWS_RESPONSE_CHECKSUM_VALIDATION=when_required
> ```

---

## 2. Cloudflare R2 Dashboard Setup

1. **Create a Bucket**
   - Go to **Cloudflare Dashboard → R2 Object Storage → Create bucket**.
   - Create a staging bucket (e.g., `sensei-staging-attachments`).

2. **Create an R2 API Token**
   - Go to **R2 Object Storage → Manage R2 API Tokens → Create API token**.
   - Set permissions to **Object Read & Write** scoped to your staging bucket.
   - Save the generated values:
     - **Access Key ID** → `AWS_ACCESS_KEY_ID`
     - **Secret Access Key** → `AWS_SECRET_ACCESS_KEY`
     - **S3 API Endpoint** (`https://<ACCOUNT_ID>.r2.cloudflarestorage.com`) → `AWS_ENDPOINT`

3. **Enable Public Access (Required for `Storage::disk('s3')->url(...)`)**
   - Open your bucket → **Settings → Public Access**.
   - Either:
     - Connect a **Custom Domain** (recommended, e.g., `https://assets-staging.yourdomain.com`), **or**
     - Enable the **R2.dev Subdomain** (e.g., `https://pub-<hash>.r2.dev`).
   - Set this base URL as `AWS_URL`.

4. **CORS Policy (if frontend accesses files directly)**
   - In your bucket → **Settings → CORS Policy**, allow `GET`, `HEAD` (and `PUT`/`POST` if doing direct browser uploads) from your staging frontend origin.

---

## 3. Staging Environment Variables

Add the following variables to your staging `.env` or hosting platform environment settings (e.g., Wasmer):

```dotenv
FILESYSTEM_DISK=s3

AWS_ACCESS_KEY_ID=<your-r2-access-key-id>
AWS_SECRET_ACCESS_KEY=<your-r2-secret-access-key>
AWS_DEFAULT_REGION=auto
AWS_BUCKET=<your-r2-bucket-name>
AWS_ENDPOINT=https://<your-cloudflare-account-id>.r2.cloudflarestorage.com
AWS_URL=https://<your-public-r2-domain-or-pub-hash.r2.dev>
AWS_USE_PATH_STYLE_ENDPOINT=false
```

| Variable | Description | Example |
| :--- | :--- | :--- |
| `FILESYSTEM_DISK` | Default Laravel filesystem disk | `s3` |
| `AWS_ACCESS_KEY_ID` | R2 API Token Access Key ID | `8f3a...` |
| `AWS_SECRET_ACCESS_KEY` | R2 API Token Secret Access Key | `9b1c...` |
| `AWS_DEFAULT_REGION` | Required by AWS SDK; R2 uses `auto` | `auto` |
| `AWS_BUCKET` | R2 bucket name | `sensei-staging-attachments` |
| `AWS_ENDPOINT` | R2 S3-compatible endpoint (without bucket name) | `https://abc123.r2.cloudflarestorage.com` |
| `AWS_URL` | Public base URL for `Storage::disk('s3')->url()` | `https://pub-xyz.r2.dev` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Use virtual-hosted-style requests | `false` |

---

## 4. Docker / Wasmer Config Cache Note

`Dockerfile` runs `php artisan config:cache` during image build:

```dockerfile
RUN php artisan config:cache && php artisan route:cache && php artisan view:cache
```

If environment variables are injected at **runtime** (after the Docker image is built), `config:cache` from build time will freeze `env(...)` values as `null`. Make sure to either:
- Run `php artisan config:clear` (or `php artisan config:cache` at container startup after runtime env vars are loaded), **or**
- Remove `php artisan config:cache` from the build step if runtime env injection is used.

---

## 5. Verification

After updating `config/filesystems.php`, setting the environment variables, and clearing/reloading config cache:

1. **Test via Artisan Tinker** (locally or in container):
   ```bash
   php artisan config:clear
   php artisan tinker --execute="Storage::disk('s3')->put('healthcheck.txt', 'ok'); echo Storage::disk('s3')->url('healthcheck.txt');"
   ```

2. **Test the Attachments Endpoint**:
   ```bash
   curl -X POST https://sensei-php-api.wasmer.app/api/attachments \
     -H "Authorization: Token <YOUR_TOKEN>" \
     -F "path=@/path/to/test-image.png"
   ```
   Expected response (`201 Created`):
   ```json
   {
     "id": 1,
     "name": "<uuid>-attach_<random>.png",
     "type": "image",
     "path": "https://<AWS_URL>/global/uploads/YYYY/MM/DD/<uuid>-attach_<random>.png"
   }
   ```

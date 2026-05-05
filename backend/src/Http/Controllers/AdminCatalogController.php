<?php

declare(strict_types=1);

namespace Locally\Http\Controllers;

use Locally\Auth\Access;
use Locally\Config\AppConfig;
use Locally\Http\Request;
use Locally\Http\Response;
use Locally\Repository\CategoryRepository;
use Locally\Repository\HomepageSectionRepository;
use Locally\Repository\ProductRepository;
use Locally\Repository\UserRepository;
use Locally\Security\UploadScanner;
use JsonException;
use PDOException;

/** Admin CRUD for catalog + homepage section ordering. */
final class AdminCatalogController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
        private readonly HomepageSectionRepository $homepage,
        private readonly AppConfig $config,
    ) {
    }

    public function categoriesList(): Response
    {
        return $this->admin(fn (): Response => Response::jsonOk([
            'items' => $this->categories->listAllAdmin(),
        ]));
    }

    public function categoryCreate(Request $request): Response
    {
        return $this->admin(function () use ($request): Response {
            try {
                $body = $request->jsonBody();
            } catch (JsonException) {
                return Response::jsonError(
                    ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                    400
                );
            }

            $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
            $slug = isset($body['slug']) && is_string($body['slug']) ? trim($body['slug']) : '';
            $description = isset($body['description']) && is_string($body['description']) ? trim($body['description']) : null;
            $parentId = null;
            if (array_key_exists('parent_id', $body)) {
                if ($body['parent_id'] === null) {
                    $parentId = null;
                } elseif (is_int($body['parent_id']) || (is_string($body['parent_id']) && ctype_digit($body['parent_id']))) {
                    $parentId = (int) $body['parent_id'];
                    if ($parentId <= 0) {
                        $parentId = null;
                    }
                }
            }
            $sortOrder = isset($body['sort_order']) && is_numeric($body['sort_order']) ? (int) $body['sort_order'] : 0;
            $isActive = !isset($body['is_active']) || $body['is_active'] === true || $body['is_active'] === 1 || $body['is_active'] === '1';

            if ($name === '' || $slug === '') {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'name and slug are required.'], 422);
            }
            if (!self::validSlug($slug)) {
                return Response::jsonError(
                    ['code' => 'VALIDATION_ERROR', 'message' => 'slug must be lowercase letters, numbers, and hyphens only.'],
                    422
                );
            }
            if ($this->categories->slugTaken($slug, null)) {
                return Response::jsonError(['code' => 'SLUG_TAKEN', 'message' => 'That category slug is already in use.'], 409);
            }
            if ($parentId !== null && $this->categories->findById($parentId) === null) {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'parent_id does not reference a category.'], 422);
            }

            try {
                $id = $this->categories->insert(
                    $name,
                    $slug,
                    $description !== '' ? $description : null,
                    $parentId,
                    $sortOrder,
                    $isActive
                );
            } catch (PDOException) {
                return Response::jsonError(['code' => 'SAVE_FAILED', 'message' => 'Could not create category.'], 500);
            }

            return Response::jsonOk(['category' => $this->categories->findById($id)]);
        });
    }

    public function categoryPatch(Request $request, string $segment): Response
    {
        return $this->admin(function () use ($request, $segment): Response {
            $id = (int) $segment;
            if ($id <= 0) {
                return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Category not found.'], 404);
            }
            $existing = $this->categories->findById($id);
            if ($existing === null) {
                return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Category not found.'], 404);
            }

            try {
                $body = $request->jsonBody();
            } catch (JsonException) {
                return Response::jsonError(
                    ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                    400
                );
            }

            $patch = [];
            if (isset($body['name']) && is_string($body['name'])) {
                $patch['name'] = trim($body['name']);
            }
            if (isset($body['slug']) && is_string($body['slug'])) {
                $patch['slug'] = trim($body['slug']);
            }
            if (array_key_exists('description', $body)) {
                $patch['description'] = is_string($body['description']) ? trim($body['description']) : null;
            }
            if (array_key_exists('parent_id', $body)) {
                if ($body['parent_id'] === null) {
                    $patch['parent_id'] = null;
                } elseif (is_int($body['parent_id']) || (is_string($body['parent_id']) && ctype_digit($body['parent_id']))) {
                    $pid = (int) $body['parent_id'];
                    $patch['parent_id'] = $pid > 0 ? $pid : null;
                }
            }
            if (isset($body['sort_order']) && is_numeric($body['sort_order'])) {
                $patch['sort_order'] = (int) $body['sort_order'];
            }
            if (array_key_exists('is_active', $body)) {
                $patch['is_active'] = $body['is_active'] === true || $body['is_active'] === 1 || $body['is_active'] === '1';
            }

            if (isset($patch['name']) && $patch['name'] === '') {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'name cannot be empty.'], 422);
            }
            if (isset($patch['slug'])) {
                if ($patch['slug'] === '') {
                    return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'slug cannot be empty.'], 422);
                }
                if (!self::validSlug($patch['slug'])) {
                    return Response::jsonError(
                        ['code' => 'VALIDATION_ERROR', 'message' => 'slug must be lowercase letters, numbers, and hyphens only.'],
                        422
                    );
                }
                if ($this->categories->slugTaken($patch['slug'], $id)) {
                    return Response::jsonError(['code' => 'SLUG_TAKEN', 'message' => 'That category slug is already in use.'], 409);
                }
            }
            if (array_key_exists('parent_id', $patch) && $patch['parent_id'] !== null) {
                if ((int) $patch['parent_id'] === $id) {
                    return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'A category cannot be its own parent.'], 422);
                }
                if ($this->categories->findById((int) $patch['parent_id']) === null) {
                    return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'parent_id does not reference a category.'], 422);
                }
            }

            if ($patch === []) {
                return Response::jsonOk(['category' => $existing]);
            }

            try {
                $this->categories->update($id, $patch);
            } catch (PDOException) {
                return Response::jsonError(['code' => 'SAVE_FAILED', 'message' => 'Could not update category.'], 500);
            }

            return Response::jsonOk(['category' => $this->categories->findById($id)]);
        });
    }

    public function productsList(Request $request): Response
    {
        return $this->admin(function () use ($request): Response {
            $q = isset($request->query['q']) && is_string($request->query['q']) ? trim($request->query['q']) : '';
            $page = max(1, (int) ($request->query['page'] ?? 1));
            $per = min(50, max(1, (int) ($request->query['per_page'] ?? 20)));
            $res = $this->products->adminListPaginated($page, $per, $q !== '' ? $q : null);

            return Response::jsonOk([
                'items' => $res['items'],
                'page' => $page,
                'per_page' => $per,
                'total' => $res['total'],
            ]);
        });
    }

    public function productCreate(Request $request): Response
    {
        return $this->admin(function () use ($request): Response {
            try {
                $body = $request->jsonBody();
            } catch (JsonException) {
                return Response::jsonError(
                    ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                    400
                );
            }

            $categoryId = isset($body['category_id']) ? (int) $body['category_id'] : 0;
            $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
            $slug = isset($body['slug']) && is_string($body['slug']) ? trim($body['slug']) : '';
            $description = isset($body['description']) && is_string($body['description']) ? trim($body['description']) : null;
            $price = isset($body['price']) && is_numeric($body['price']) ? (float) $body['price'] : -1.0;
            $discount = null;
            if (array_key_exists('discount_price', $body)) {
                if ($body['discount_price'] === null || $body['discount_price'] === '') {
                    $discount = null;
                } elseif (is_numeric($body['discount_price'])) {
                    $discount = (float) $body['discount_price'];
                }
            }
            $avail = isset($body['availability_status']) && is_string($body['availability_status'])
                ? trim($body['availability_status'])
                : 'in_stock';
            $isFeatured = isset($body['is_featured']) && ($body['is_featured'] === true || $body['is_featured'] === 1 || $body['is_featured'] === '1');
            $isTrending = isset($body['is_trending']) && ($body['is_trending'] === true || $body['is_trending'] === 1 || $body['is_trending'] === '1');

            if ($categoryId <= 0 || $name === '' || $slug === '' || $price < 0) {
                return Response::jsonError(
                    ['code' => 'VALIDATION_ERROR', 'message' => 'category_id, name, slug, and price are required.'],
                    422
                );
            }
            if ($this->categories->findById($categoryId) === null) {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'category_id is invalid.'], 422);
            }
            if (!self::validSlug($slug)) {
                return Response::jsonError(
                    ['code' => 'VALIDATION_ERROR', 'message' => 'slug must be lowercase letters, numbers, and hyphens only.'],
                    422
                );
            }
            if ($this->products->slugTaken($slug, null)) {
                return Response::jsonError(['code' => 'SLUG_TAKEN', 'message' => 'That product slug is already in use.'], 409);
            }
            if (!in_array($avail, ['in_stock', 'out_of_stock', 'preorder'], true)) {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'availability_status is invalid.'], 422);
            }

            try {
                $pid = $this->products->createWithDefaultVariant(
                    $categoryId,
                    $name,
                    $slug,
                    $description !== null && $description !== '' ? $description : null,
                    $price,
                    $discount,
                    $avail,
                    $isFeatured,
                    $isTrending
                );
            } catch (PDOException) {
                return Response::jsonError(['code' => 'SAVE_FAILED', 'message' => 'Could not create product.'], 500);
            }

            return Response::jsonOk(['product' => $this->products->findByIdForAdmin($pid)]);
        });
    }

    public function productPatch(Request $request, string $segment): Response
    {
        return $this->admin(function () use ($request, $segment): Response {
            $id = (int) $segment;
            if ($id <= 0) {
                return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Product not found.'], 404);
            }
            $existing = $this->products->findByIdForAdmin($id);
            if ($existing === null) {
                return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Product not found.'], 404);
            }

            try {
                $body = $request->jsonBody();
            } catch (JsonException) {
                return Response::jsonError(
                    ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                    400
                );
            }

            $patch = [];
            if (isset($body['name']) && is_string($body['name'])) {
                $patch['name'] = trim($body['name']);
            }
            if (isset($body['slug']) && is_string($body['slug'])) {
                $patch['slug'] = trim($body['slug']);
            }
            if (array_key_exists('description', $body)) {
                $patch['description'] = is_string($body['description']) ? trim($body['description']) : null;
            }
            if (isset($body['category_id']) && is_numeric($body['category_id'])) {
                $patch['category_id'] = (int) $body['category_id'];
            }
            if (isset($body['price']) && is_numeric($body['price'])) {
                $patch['price'] = (float) $body['price'];
            }
            if (array_key_exists('discount_price', $body)) {
                if ($body['discount_price'] === null || $body['discount_price'] === '') {
                    $patch['discount_price'] = null;
                } elseif (is_numeric($body['discount_price'])) {
                    $patch['discount_price'] = (float) $body['discount_price'];
                }
            }
            if (isset($body['availability_status']) && is_string($body['availability_status'])) {
                $patch['availability_status'] = trim($body['availability_status']);
            }
            if (array_key_exists('is_featured', $body)) {
                $patch['is_featured'] = $body['is_featured'] === true || $body['is_featured'] === 1 || $body['is_featured'] === '1';
            }
            if (array_key_exists('is_trending', $body)) {
                $patch['is_trending'] = $body['is_trending'] === true || $body['is_trending'] === 1 || $body['is_trending'] === '1';
            }

            if (isset($patch['name']) && $patch['name'] === '') {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'name cannot be empty.'], 422);
            }
            if (isset($patch['slug'])) {
                if ($patch['slug'] === '') {
                    return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'slug cannot be empty.'], 422);
                }
                if (!self::validSlug($patch['slug'])) {
                    return Response::jsonError(
                        ['code' => 'VALIDATION_ERROR', 'message' => 'slug must be lowercase letters, numbers, and hyphens only.'],
                        422
                    );
                }
                if ($this->products->slugTaken($patch['slug'], $id)) {
                    return Response::jsonError(['code' => 'SLUG_TAKEN', 'message' => 'That product slug is already in use.'], 409);
                }
            }
            if (isset($patch['category_id']) && $patch['category_id'] > 0) {
                if ($this->categories->findById($patch['category_id']) === null) {
                    return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'category_id is invalid.'], 422);
                }
            }
            if (isset($patch['availability_status']) && !in_array($patch['availability_status'], ['in_stock', 'out_of_stock', 'preorder'], true)) {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'availability_status is invalid.'], 422);
            }
            if (isset($patch['price']) && $patch['price'] < 0) {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'price cannot be negative.'], 422);
            }

            if ($patch === []) {
                return Response::jsonOk(['product' => $existing]);
            }

            try {
                $this->products->updateAdmin($id, $patch);
            } catch (PDOException) {
                return Response::jsonError(['code' => 'SAVE_FAILED', 'message' => 'Could not update product.'], 500);
            }

            return Response::jsonOk(['product' => $this->products->findByIdForAdmin($id)]);
        });
    }

    private const MAX_IMAGE_BYTES = 2_500_000;

    public function productImageUpload(Request $request): Response
    {
        return $this->admin(function () use ($request): Response {
            $ct = $request->header('Content-Type');
            if ($ct === null || stripos($ct, 'multipart/form-data') === false) {
                return Response::jsonError(
                    ['code' => 'UNSUPPORTED_MEDIA_TYPE', 'message' => 'Use multipart/form-data with fields product_id and file.'],
                    415
                );
            }

            $pid = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
            if ($pid <= 0) {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'product_id is required.'], 422);
            }
            if ($this->products->findByIdForAdmin($pid) === null) {
                return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Product not found.'], 404);
            }

            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'file is required.'], 422);
            }
            $f = $_FILES['file'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return Response::jsonError(['code' => 'UPLOAD_FAILED', 'message' => 'File upload failed.'], 400);
            }
            if (($f['size'] ?? 0) > self::MAX_IMAGE_BYTES) {
                return Response::jsonError(['code' => 'FILE_TOO_LARGE', 'message' => 'Image must be at most about 2.5 MB.'], 413);
            }

            $tmp = (string) ($f['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                return Response::jsonError(['code' => 'UPLOAD_FAILED', 'message' => 'Invalid upload.'], 400);
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp);
            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];
            if ($mime === false || !isset($extMap[$mime])) {
                return Response::jsonError(
                    ['code' => 'VALIDATION_ERROR', 'message' => 'Only JPEG, PNG, or WebP images are allowed.'],
                    422
                );
            }
            $ext = $extMap[$mime];
            $imgMeta = @getimagesize($tmp);
            if (!is_array($imgMeta)) {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'Uploaded file is not a valid image.'], 422);
            }
            $w = isset($imgMeta[0]) ? (int) $imgMeta[0] : 0;
            $h = isset($imgMeta[1]) ? (int) $imgMeta[1] : 0;
            if ($w <= 0 || $h <= 0 || $w > 5000 || $h > 5000) {
                return Response::jsonError(
                    ['code' => 'VALIDATION_ERROR', 'message' => 'Image dimensions must be between 1x1 and 5000x5000.'],
                    422
                );
            }

            $publicRoot = dirname(__DIR__, 3) . '/public';
            $relDir = 'uploads/products';
            $destDir = $publicRoot . '/' . $relDir;
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                return Response::jsonError(['code' => 'SERVER_ERROR', 'message' => 'Could not create upload directory.'], 500);
            }

            $basename = $pid . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
            $destFs = $destDir . '/' . $basename;
            if (!move_uploaded_file($tmp, $destFs)) {
                return Response::jsonError(['code' => 'UPLOAD_FAILED', 'message' => 'Could not store file.'], 500);
            }

            $scan = (new UploadScanner($this->config->uploadScanCommand, $this->config->uploadScanRequired))
                ->scan($destFs);
            if (!$scan['ok']) {
                @unlink($destFs);
                return Response::jsonError(
                    ['code' => 'UPLOAD_REJECTED', 'message' => 'Upload rejected by scanner.'],
                    422
                );
            }

            $webPath = '/' . $relDir . '/' . $basename;
            $alt = isset($_POST['alt_text']) && is_string($_POST['alt_text']) ? trim($_POST['alt_text']) : null;
            if ($alt === '') {
                $alt = null;
            }
            $isPrimary = isset($_POST['is_primary'])
                && ($_POST['is_primary'] === '1' || $_POST['is_primary'] === 'true' || $_POST['is_primary'] === true || $_POST['is_primary'] === 1);
            $sort = $this->products->nextImageSortOrder($pid);

            try {
                $imgId = $this->products->insertProductImage($pid, $webPath, $alt, $sort, $isPrimary);
            } catch (PDOException) {
                @unlink($destFs);

                return Response::jsonError(['code' => 'SAVE_FAILED', 'message' => 'Could not save image record.'], 500);
            }

            return Response::jsonOk([
                'image' => [
                    'id' => $imgId,
                    'product_id' => $pid,
                    'path' => $webPath,
                    'alt_text' => $alt,
                    'sort_order' => $sort,
                    'is_primary' => $isPrimary,
                ],
            ]);
        });
    }

    public function homepageSectionsList(): Response
    {
        return $this->admin(fn (): Response => Response::jsonOk([
            'items' => $this->homepage->listAll(),
        ]));
    }

    public function homepageReorder(Request $request): Response
    {
        return $this->admin(function () use ($request): Response {
            try {
                $body = $request->jsonBody();
            } catch (JsonException) {
                return Response::jsonError(
                    ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                    400
                );
            }

            $ids = $body['ids'] ?? null;
            if (!is_array($ids)) {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'ids must be an array of section ids in display order.'], 422);
            }

            try {
                $this->homepage->reorder($ids);
            } catch (\InvalidArgumentException $e) {
                return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()], 422);
            } catch (PDOException) {
                return Response::jsonError(['code' => 'SAVE_FAILED', 'message' => 'Could not reorder sections.'], 500);
            }

            return Response::jsonOk(['items' => $this->homepage->listAll()]);
        });
    }

    /**
     * @param callable(): Response $fn
     */
    private function admin(callable $fn): Response
    {
        $block = Access::ensureRoles($this->users, ['admin']);
        if ($block !== null) {
            return $block;
        }

        return $fn();
    }

    private static function validSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }
}

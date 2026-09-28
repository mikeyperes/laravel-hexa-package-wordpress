<?php

use hexa_package_user_roles\Http\Middleware\EnsureAdminAccess;
use hexa_package_wordpress\Http\Controllers\ApplicationPasswordCallbackController;
use hexa_package_wordpress\Http\Controllers\MediaOperationController;
use hexa_package_wordpress\Http\Controllers\WordPressController;
use Illuminate\Support\Facades\Route;

Route::middleware(["web", "auth", "locked", "system_lock", "two_factor", "role", EnsureAdminAccess::class])->group(function () {
    Route::get("/raw-wordpress", [WordPressController::class, "raw"])->name("wordpress.index");

    Route::post("/wordpress/test-connection", [WordPressController::class, "testConnection"])->name("wordpress.test-connection");
    Route::post("/wordpress/categories", [WordPressController::class, "categories"])->name("wordpress.categories");
    Route::post("/wordpress/tags", [WordPressController::class, "tags"])->name("wordpress.tags");
    Route::post("/wordpress/create-post", [WordPressController::class, "createPost"])->name("wordpress.create-post");
    Route::post("/wordpress/acf/education-metadata", [WordPressController::class, "educationMetadata"])->name("wordpress.acf.education-metadata");
    Route::post("/wordpress/acf/article-metadata", [WordPressController::class, "articleMetadata"])->name("wordpress.acf.article-metadata");
    Route::get("/wordpress/media-operations/{operationId}", [MediaOperationController::class, "show"])
        ->where("operationId", "[A-Za-z0-9][A-Za-z0-9._:-]{7,119}")
        ->name("wordpress.media-operations.show");
});

// WordPress's Authorize Application redirect. Public by design: the one-time
// 64-character state issued by ApplicationPasswordAuthorization is the guard.
Route::middleware(["web", "throttle:30,1"])
    ->get("/wordpress/app-password/callback/{state}", ApplicationPasswordCallbackController::class)
    ->where("state", "[A-Za-z0-9]{64}")
    ->name("wordpress.app-password.callback");

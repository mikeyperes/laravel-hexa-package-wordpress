<?php

use hexa_package_wordpress\Http\Controllers\WordPressController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WordPress Package Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['web', 'auth', 'locked', 'system_lock', 'two_factor', 'role'])->group(function () {
    // Raw dev view
    Route::get('/raw-wordpress', [WordPressController::class, 'raw'])->name('wordpress.index');

    // AJAX endpoints
    Route::post('/wordpress/test-connection', [WordPressController::class, 'testConnection'])->name('wordpress.test-connection');
    Route::post('/wordpress/categories', [WordPressController::class, 'categories'])->name('wordpress.categories');
    Route::post('/wordpress/tags', [WordPressController::class, 'tags'])->name('wordpress.tags');
    Route::post('/wordpress/create-post', [WordPressController::class, 'createPost'])->name('wordpress.create-post');
});

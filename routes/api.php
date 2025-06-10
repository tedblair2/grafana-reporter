<?php

use App\Http\Controllers\PdfController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPdf\PdfBuilder;

// Route::get('/pdf',[PdfController::class,'generatePdf']);

Route::get('/pdf/{id}',[PdfController::class,'getDashboadPanels']);

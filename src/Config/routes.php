<?php
\Route::match(['get', 'post'],"/pym-magic-box/{merchant}/{method}/{action}","\Mantonio84\pymMagicBox\Controllers\pymListenController@merchantAction")->name("pym-magic-box.merchant-action");
\Route::post("/pym-wb/{merchant}/{method}","\Mantonio84\pymMagicBox\Controllers\pymListenController@webhook")->name("pym-magic-box.webhook");

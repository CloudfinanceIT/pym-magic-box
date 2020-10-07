<?php
\Route::match(['get', 'post'],"/pym-magic-box/{merchant}/{method}/{action}","\Mantonio84\pymMagicBox\Controllers\pymListenController")->name("pym-magic-box");

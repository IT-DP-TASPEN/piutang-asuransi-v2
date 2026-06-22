<?php

namespace App\Http\Controllers;

use App\Models\InsuranceCoverLetter;
use Illuminate\Support\Facades\Gate;

class PreviewInsuranceCoverLetterController extends Controller
{
    public function __invoke(InsuranceCoverLetter $letter)
    {
        abort_unless(Gate::allows('view', $letter), 403);
        abort_if(blank($letter->rendered_html), 404);

        return response($letter->rendered_html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}

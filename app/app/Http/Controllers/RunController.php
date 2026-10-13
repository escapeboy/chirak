<?php

namespace App\Http\Controllers;

use App\Models\Run;
use App\Support\DemoToken;
use App\Support\RunReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RunController extends Controller
{
    public function index()
    {
        $runs = Run::with(['evaluations', 'iterations'])->latest()->get();

        return view('runs.index', ['runs' => $runs->map(fn (Run $run) => [
            'run' => $run,
            'report' => new RunReport($run),
        ])]);
    }

    public function unlock(Request $request)
    {
        if (! DemoToken::attempt((string) $request->input('demo_token'))) {
            return back()->with('unlock_error', 'Wrong demo token.');
        }

        return back();
    }

    public function report(Run $run)
    {
        return view('runs.report', ['run' => $run, 'report' => new RunReport($run)]);
    }

    public function adapter(Run $run, int $iteration)
    {
        $path = $run->iterations()->where('n', $iteration)->value('adapter_archive_local');
        abort_unless($path && Storage::disk('runs')->exists($path), 404);

        return Storage::disk('runs')->download($path, "{$run->slug}-iter-$iteration-adapter.tar.gz");
    }
}

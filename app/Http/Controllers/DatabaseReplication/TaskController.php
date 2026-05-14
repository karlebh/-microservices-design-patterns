<?php

namespace App\Http\Controllers\DatabaseReplication;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    // READ -> replica
    public function index()
    {
        // $host = DB::select('select @@hostname as host'); //mysql
        $host = DB::select('select inet_server_addr() as host');

        return response()->json([
            'server' => $host,
            'data' => Task::latest()->get(),
        ]);
    }

    // READ -> replica
    public function show(Task $task)
    {
        $host = DB::select('select inet_server_addr() as host');

        return response()->json([
            'server' => $host,
            'data' => $task,
        ]);
    }

    // WRITE -> primary
    public function store(Request $request)
    {
        $request->validate([
            'title' => ['required'],
        ]);

        $host = DB::select('select inet_server_addr() as host');

        $task = Task::create([
            'title' => $request->title,
        ]);

        return response()->json([
            'server' => $host,
            'data' => $task,
        ]);
    }

    // WRITE -> primary
    public function update(Request $request, Task $task)
    {
        $host = DB::select('select inet_server_addr() as host');

        $task->update([
            'title' => $request->title,
            'completed' => $request->completed,
        ]);

        return response()->json([
            'server' => $host,
            'data' => $task,
        ]);
    }

    // WRITE -> primary
    public function destroy(Task $task)
    {
        $host = DB::select('select inet_server_addr() as host');

        $task->delete();

        return response()->json([
            'server' => $host,
            'message' => 'Deleted',
        ]);
    }
}

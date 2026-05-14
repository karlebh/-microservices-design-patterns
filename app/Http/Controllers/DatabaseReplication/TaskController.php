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
        return response()->json([
            'data' => Task::latest()->get(),
        ]);
    }

    // READ -> replica
    public function show(Task $task)
    {
        return response()->json([
            'data' => $task,
        ]);
    }

    // WRITE -> primary
    public function store(Request $request)
    {
        $request->validate([
            'title' => ['required'],
        ]);

        $task = Task::create([
            'title' => $request->title,
        ]);

        return response()->json([
            'data' => $task,
        ]);
    }

    // WRITE -> primary
    public function update(Request $request, Task $task)
    {
        $task->update([
            'title' => $request->title,
            'completed' => $request->completed,
        ]);

        return response()->json([
            'data' => $task,
        ]);
    }

    // WRITE -> primary
    public function destroy(Task $task)
    {
        $task->delete();

        return response()->json([
            'message' => 'Deleted',
        ]);
    }
}

<?php

namespace Patterns\DataArchitecture\ReadReplicas\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Patterns\DataArchitecture\ReadReplicas\Models\Task;

/**
 * Nothing in this controller mentions replicas, and that is the point:
 * Laravel routes SELECTs to a `read` host and everything else to the `write`
 * host based on config/database.php alone.
 */
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

        // With 'sticky' => true, any read later in THIS request would also go
        // to the primary, so the request always sees its own write.
        return response()->json([
            'data' => $task,
        ]);
    }

    // WRITE -> primary
    public function update(Request $request, Task $task)
    {
        $task->update($request->validate([
            'title' => ['sometimes', 'required'],
            'completed' => ['sometimes', 'boolean'],
        ]));

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

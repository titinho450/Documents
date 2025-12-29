<?php

namespace App\Http\Controllers;

use App\Models\Posts;
use App\Models\UserLedger;
use Illuminate\Http\Request;

class PostsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $posts = Posts::with('user')->where('status', 'pending')->get();

        return view('app.main.post.list', compact('posts'));
    }

    public function forum()
    {
        $posts = Posts::with('user')
            ->where('status', 'approved')
            ->latest()
            ->get();
        $title = env('APP_NAME');

        return view('app.main.post.forum', compact('posts', 'title'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('app.main.post.create');
    }

    public function approve($id, Request $request)
    {
        $post = Posts::findOrFail($id);
        $user = $post->user;

        $validated = $request->validate([
            'commission' => 'required|numeric|min:0'
        ]);

        // Atualiza o status do post
        $post->value = $validated['commission'];
        $post->status = 'approved';
        $post->save();

        // Adiciona a comissão ao saldo do usuário
        $user->balance += $validated['commission'];
        $user->save();

        $ledger = UserLedger::create([
            'user_id' => $user->id,
            'reason' => 'post_approve',
            'perticulation' => 'Post approval commission - Post ID: ' . $post->id,
            'amount' => $validated['commission'],
            'debit' => 0,
            'credit' => $validated['commission'],
            'status' => 'approved',
            'date' => now()
        ]);

        return response()->json([
            'message' => 'Post aprovado e comissão adicionada com sucesso!',
            'status' => 'success'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'content' => 'required|string',
            'first_image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'second_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        // Verifica se os arquivos foram enviados
        if (!$request->hasFile('first_image')) {
            return response()->json([
                'message' => 'As imagens são obrigatórias'
            ], 422);
        }

        $firstImageName = time() . '_first.' . $request->file('first_image')->getClientOriginalExtension();
        $request->file('first_image')->storeAs('upload/posts', $firstImageName, 'public');

        // Processa a segunda imagem apenas se ela foi enviada
        $secondImageName = null;
        if ($request->hasFile('second_image')) {
            $secondImageName = time() . '_second.' . $request->file('second_image')->getClientOriginalExtension();
            $request->file('second_image')->storeAs('upload/posts', $secondImageName, 'public');
        }

        $post = new Posts();
        $post->user_id = $user->id;
        $post->content = $validated['content'];
        $post->first_image = $firstImageName;
        $post->second_image = $secondImageName;
        $post->status = 'pending';
        $post->save();

        return redirect()->route('posts.create')->with('success', 'Post criado com sucesso!');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}

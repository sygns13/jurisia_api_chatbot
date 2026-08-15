<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MainConsultaController extends Controller
{
    /**
     * Muestra una lista del recurso.
     */
    public function index()
    {
        $consultas = MainConsulta::latest()->paginate(10);
        return view('main_consultas.index', compact('consultas'));
    }

    /**
     * Muestra el formulario para crear un nuevo recurso.
     */
    public function create()
    {
        return view('main_consultas.create');
    }

    /**
     * Almacena un recurso recién creado en la base de datos.
     */
    public function store(Request $request)
    {
        $request->validate([
            'chatId' => 'required|string|max:100',
            'service' => 'nullable|string|max:50',
            'message' => 'nullable|string',
        ]);

        MainConsulta::create($request->all());

        return redirect()->route('main-consultas.index')
                         ->with('success', 'Consulta creada exitosamente.');
    }

    /**
     * Muestra el recurso especificado.
     */
    public function show(MainConsulta $mainConsulta)
    {
        return view('main_consultas.show', compact('mainConsulta'));
    }

    /**
     * Muestra el formulario para editar el recurso especificado.
     */
    public function edit(MainConsulta $mainConsulta)
    {
        return view('main_consultas.edit', compact('mainConsulta'));
    }

    /**
     * Actualiza el recurso especificado en la base de datos.
     */
    public function update(Request $request, MainConsulta $mainConsulta)
    {
        $request->validate([
            'chatId' => 'required|string|max:100',
            'service' => 'nullable|string|max:50',
            'message' => 'nullable|string',
            'status' => 'required|integer',
            'step' => 'required|integer',
        ]);

        $mainConsulta->update($request->all());

        return redirect()->route('main-consultas.index')
                         ->with('success', 'Consulta actualizada exitosamente.');
    }

    /**
     * Elimina el recurso especificado de la base de datos.
     */
    public function destroy(MainConsulta $mainConsulta)
    {
        $mainConsulta->delete();

        return redirect()->route('main-consultas.index')
                         ->with('success', 'Consulta eliminada exitosamente.');
    }
}

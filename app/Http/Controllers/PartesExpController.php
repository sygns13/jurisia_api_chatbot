<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PartesExpController extends Controller
{
    /**
     * Muestra una lista del recurso.
     */
    public function index()
    {
        $partes = PartesExp::latest()->paginate(10);
        return view('partes_exp.index', compact('partes'));
    }

    /**
     * Muestra el formulario para crear un nuevo recurso.
     */
    public function create()
    {
        return view('partes_exp.create');
    }

    /**
     * Almacena un recurso recién creado en la base de datos.
     */
    public function store(Request $request)
    {
        $request->validate([
            'nUnico' => 'required|integer',
            'xNombres' => 'required|string|max:255',
            'chatId' => 'required|string|max:100',
            // Agrega aquí el resto de validaciones
        ]);

        PartesExp::create($request->all());

        return redirect()->route('partes-exp.index')
                         ->with('success', 'Parte creada exitosamente.');
    }

    /**
     * Muestra el recurso especificado.
     */
    public function show(PartesExp $partesExp)
    {
        return view('partes_exp.show', compact('partesExp'));
    }

    /**
     * Muestra el formulario para editar el recurso especificado.
     */
    public function edit(PartesExp $partesExp)
    {
        return view('partes_exp.edit', compact('partesExp'));
    }

    /**
     * Actualiza el recurso especificado en la base de datos.
     */
    public function update(Request $request, PartesExp $partesExp)
    {
        $request->validate([
            'nUnico' => 'required|integer',
            'xNombres' => 'required|string|max:255',
            'chatId' => 'required|string|max:100',
            // Agrega aquí el resto de validaciones
        ]);

        $partesExp->update($request->all());

        return redirect()->route('partes-exp.index')
                         ->with('success', 'Parte actualizada exitosamente.');
    }

    /**
     * Elimina el recurso especificado de la base de datos.
     */
    public function destroy(PartesExp $partesExp)
    {
        $partesExp->delete();

        return redirect()->route('partes-exp.index')
                         ->with('success', 'Parte eliminada exitosamente.');
    }
}

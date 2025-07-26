<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CabExpedienteController extends Controller
{
    /**
     * Muestra una lista del recurso.
     */
    public function index()
    {
        $expedientes = CabExpediente::latest()->paginate(10);
        return view('cab_expedientes.index', compact('expedientes'));
    }

    /**
     * Muestra el formulario para crear un nuevo recurso.
     */
    public function create()
    {
        return view('cab_expedientes.create');
    }

    /**
     * Almacena un recurso recién creado en la base de datos.
     */
    public function store(Request $request)
    {
        $request->validate([
            'xFormato' => 'required|string|max:50',
            'chatId' => 'required|string|max:100',
            // Agrega aquí el resto de validaciones
        ]);

        CabExpediente::create($request->all());

        return redirect()->route('cab-expedientes.index')
                         ->with('success', 'Expediente creado exitosamente.');
    }

    /**
     * Muestra el recurso especificado.
     */
    public function show(CabExpediente $cabExpediente)
    {
        return view('cab_expedientes.show', compact('cabExpediente'));
    }

    /**
     * Muestra el formulario para editar el recurso especificado.
     */
    public function edit(CabExpediente $cabExpediente)
    {
        return view('cab_expedientes.edit', compact('cabExpediente'));
    }

    /**
     * Actualiza el recurso especificado en la base de datos.
     */
    public function update(Request $request, CabExpediente $cabExpediente)
    {
        $request->validate([
            'xFormato' => 'required|string|max:50',
            'chatId' => 'required|string|max:100',
            // Agrega aquí el resto de validaciones
        ]);

        $cabExpediente->update($request->all());

        return redirect()->route('cab-expedientes.index')
                         ->with('success', 'Expediente actualizado exitosamente.');
    }

    /**
     * Elimina el recurso especificado de la base de datos.
     */
    public function destroy(CabExpediente $cabExpediente)
    {
        $cabExpediente->delete();

        return redirect()->route('cab-expedientes.index')
                         ->with('success', 'Expediente eliminado exitosamente.');
    }
}

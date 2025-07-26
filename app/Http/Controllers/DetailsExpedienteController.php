<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DetailsExpedienteController extends Controller
{
    /**
     * Muestra una lista del recurso.
     */
    public function index()
    {
        $detalles = DetailsExpediente::latest()->paginate(10);
        return view('details_expedientes.index', compact('detalles'));
    }

    /**
     * Muestra el formulario para crear un nuevo recurso.
     */
    public function create()
    {
        return view('details_expedientes.create');
    }

    /**
     * Almacena un recurso recién creado en la base de datos.
     */
    public function store(Request $request)
    {
        $request->validate([
            'nUnico' => 'required|integer',
            'xFormato' => 'required|string|max:100',
            'chatId' => 'required|string|max:100',
            // Agrega aquí el resto de validaciones
        ]);

        DetailsExpediente::create($request->all());

        return redirect()->route('details-expedientes.index')
                         ->with('success', 'Detalle de expediente creado exitosamente.');
    }

    /**
     * Muestra el recurso especificado.
     */
    public function show(DetailsExpediente $detailsExpediente)
    {
        return view('details_expedientes.show', compact('detailsExpediente'));
    }

    /**
     * Muestra el formulario para editar el recurso especificado.
     */
    public function edit(DetailsExpediente $detailsExpediente)
    {
        return view('details_expedientes.edit', compact('detailsExpediente'));
    }

    /**
     * Actualiza el recurso especificado en la base de datos.
     */
    public function update(Request $request, DetailsExpediente $detailsExpediente)
    {
        $request->validate([
            'nUnico' => 'required|integer',
            'xFormato' => 'required|string|max:100',
            'chatId' => 'required|string|max:100',
            // Agrega aquí el resto de validaciones
        ]);

        $detailsExpediente->update($request->all());

        return redirect()->route('details-expedientes.index')
                         ->with('success', 'Detalle de expediente actualizado exitosamente.');
    }

    /**
     * Elimina el recurso especificado de la base de datos.
     */
    public function destroy(DetailsExpediente $detailsExpediente)
    {
        $detailsExpediente->delete();

        return redirect()->route('details-expedientes.index')
                         ->with('success', 'Detalle de expediente eliminado exitosamente.');
    }
}

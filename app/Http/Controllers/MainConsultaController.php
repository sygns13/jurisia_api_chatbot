<?php

namespace App\Http\Controllers;

use App\Models\MainConsulta;
use App\Models\CabExpediente;
use App\Models\PartesExp;
use App\Models\DetailsExpediente;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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

    public function getMainConsulta(Request $request) : JsonResponse
    {
        $regDate = $request->regDate ?? '2000-01-01'; // Valor por defecto si no se proporciona regDate

        // Aquí puedes implementar la lógica para manejar la consulta principal
        $consultas = MainConsulta::where('regDate', $regDate)->get();
        
        foreach ($consultas as $consulta) {
            // Aquí puedes agregar lógica adicional para procesar cada consulta
            // Por ejemplo, cargar relaciones o formatear datos
            if($consulta->step == 4){
                $prefijo = "done-";
                $sufijo = "-done";

                $consulta->chatId = str_replace([$prefijo, $sufijo], '', $consulta->chatId);
            }

            $consulta->cabExpediente = CabExpediente::where('chatId', $consulta->chatId ?? '')
                                                    ->where('xFormato', $consulta->message ?? '')
                                                    ->orderBy('id', 'desc')
                                                    ->first();

            if ($consulta->cabExpediente != null && $consulta->cabExpediente->nUnico != null) {
                $consulta->partesExp = PartesExp::where('chatId', $consulta->chatId ?? '')
                                                ->where('nUnico', $consulta->cabExpediente->nUnico ?? 0)->get()
                                                ->unique(function ($item) {
                                                // 2. Crea una "llave" única concatenando todos los campos
                                                return $item->cTipoPersona . '-' . $item->xDescTipoPersona . '-' . 
                                                    $item->indTipoParte . '-' . $item->xDescParte . '-' . 
                                                    $item->xApePaterno . '-' . $item->xApeMaterno . '-' . 
                                                    $item->xNombres . '-' . $item->xDocId . '-' . 
                                                    $item->cTipo . '-' . $item->xTipoDoc . '-' . 
                                                    $item->xAbrevi . '-' . $item->indActivo . '-' . 
                                                    $item->nUnico;
                                            });

                $consulta->detailsExp = DetailsExpediente::where('chatId', $consulta->chatId ?? '')
                                                        ->where('nUnico', $consulta->cabExpediente->nUnico ?? nUnico)->get()
                                                        ->unique(function ($item) {
                                                // 2. Luego, creas una "llave" única para cada registro concatenando los valores de las columnas.
                                                return $item->nUnico . '|' .
                                                    $item->xFormato . '|' .
                                                    $item->xNomInstancia . '|' .
                                                    $item->codEspecialidad . '|' .
                                                    $item->xDescMateria . '|' .
                                                    $item->fInicio . '|' .
                                                    $item->xDescEstado . '|' .
                                                    $item->codUbicacion . '|' .
                                                    $item->xDescUbicacion . '|' .
                                                    $item->usuarioJuez . '|' .
                                                    $item->juez . '|' .
                                                    $item->usuarioSecretario . '|' .
                                                    $item->secretario . '|' .
                                                    $item->tipoExpediente . '|' .
                                                    $item->parte . '|' .
                                                    $item->indTipoParte . '|' .
                                                    $item->xDescParte;
                                            });
            }                                                    
            
        }

        return response()->json([
            'success' => true,
            'data' => $consultas,
        ]);
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

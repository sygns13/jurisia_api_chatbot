<?php

namespace App\Http\Controllers;

use App\Models\MainConsulta;
use App\Models\CabExpediente;
use App\Models\PartesExp;
use App\Models\DetailsExpediente;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ApiController extends Controller
{
    public function getPendingConsultas(): JsonResponse
    {
        try {
            // Realiza la consulta a la base de datos usando el modelo de Eloquent.
            $consultasPendientes = MainConsulta::where('status', 1)
                                               ->where('step', 1)
                                               ->get();

            // Retorna una respuesta JSON estándar con los datos.
            return response()->json([
                'success' => true,
                'itemFound' => $consultasPendientes->count() > 0,
                'data'    => $consultasPendientes,
                'message' => 'Se recuperaron ' . $consultasPendientes->count() . ' consultas pendientes.'
            ], 200);

        } catch (\Exception $e) {
            // En caso de un error en la base de datos u otro problema,
            // se devuelve una respuesta de error del servidor.
            return response()->json([
                'success' => false,
                'itemFound' => $consultasPendientes->count() > 0,
                'message' => 'Ocurrió un error al recuperar las consultas.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function updateConsulta(Request $request): JsonResponse
    {
        // Validación básica de la estructura del request
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:MainConsulta,id',
            'chatId' => 'required|string',
            'expFound' => 'required|boolean',
            'cabExpedienteChat' => 'nullable|required_if:expFound,true|array',
            'listPartes' => 'nullable|required_if:expFound,true|array',
            'detailsExp' => 'nullable|required_if:expFound,true|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $request->all();
        $mainConsulta = MainConsulta::find($data['id']);

        try {
            // Usamos una transacción para asegurar la integridad de los datos
            DB::transaction(function () use ($data, $mainConsulta) {
                
                if ($data['expFound']) {
                    // 1. Poblar CabExpediente
                    CabExpediente::create([
                        'xFormato' => $data['cabExpedienteChat']['xformato'] ?? null,
                        'nUnico' => $data['cabExpedienteChat']['nunico'] ?? null,
                        'nIncidente' => $data['cabExpedienteChat']['nincidente'] ?? null,
                        'tipoExpediente' => $data['cabExpedienteChat']['tipoExpediente'] ?? null,
                        'codEspecialidad' => $data['cabExpedienteChat']['codEspecialidad'] ?? null,
                        'codInstancia' => $data['cabExpedienteChat']['codInstancia'] ?? null,
                        'instancia' => $data['cabExpedienteChat']['instancia'] ?? null,
                        'organoJurisd' => $data['cabExpedienteChat']['organoJurisd'] ?? null,
                        'sede' => $data['cabExpedienteChat']['sede'] ?? null,
                        'indAnulado' => $data['cabExpedienteChat']['indAnulado'] ?? null,
                        'indUltimo' => $data['cabExpedienteChat']['indUltimo'] ?? null,
                        'chatId' => $mainConsulta->chatId,
                        // --- CAMPOS DE FECHA Y HORA ACTUALES ---
                        'regDate' => now()->toDateString(),
                        'regDatetime' => now(),
                        'regTimestamp' => now()->timestamp,
                    ]);

                    // 2. Poblar PartesExp
                    foreach ($data['listPartes'] as $parte) {
                        PartesExp::create([
                            'cTipoPersona' => $parte['tipoPersona'] ?? null,
                            'xDescTipoPersona' => $parte['descTipoPersona'] ?? null,
                            'indTipoParte' => $parte['tipoParte'] ?? null,
                            'xDescParte' => $parte['descTipoParte'] ?? null,
                            'xApePaterno' => $parte['apePaterno'] ?? null,
                            'xApeMaterno' => $parte['apeMaterno'] ?? null,
                            'xNombres' => $parte['nombres'] ?? null,
                            'xDocId' => $parte['docId'] ?? null,
                            'cTipo' => $parte['tipoDoc'] ?? null,
                            'xTipoDoc' => $parte['descTipoDoc'] ?? null,
                            'xAbrevi' => $parte['abreviaturaTipoDoc'] ?? null,
                            'indActivo' => $parte['activo'] ?? null,
                            'nUnico' => $parte['nunico'] ?? null,
                            'chatId' => $mainConsulta->chatId,
                            // --- CAMPOS DE FECHA Y HORA ACTUALES ---
                            'regDate' => now()->toDateString(),
                            'regDatetime' => now(),
                            'regTimestamp' => now()->timestamp,
                        ]);
                    }

                    // 3. Poblar DetailsExpediente
                    foreach ($data['detailsExp'] as $detail) {
                        DetailsExpediente::create([
                            'nUnico' => $data['cabExpedienteChat']['nunico'] ?? null,
                            'xFormato' => $detail['numeroExpediente'] ?? null,
                            'xNomInstancia' => $detail['instancia'] ?? null,
                            'codEspecialidad' => $detail['codigoEspecialidad'] ?? null,
                            'xDescMateria' => $detail['materia'] ?? null,
                            'fInicio' => $detail['fechaInicio'] ?? null,
                            'xDescEstado' => $detail['estadoExpediente'] ?? null,
                            'codUbicacion' => $detail['codigoUbicacion'] ?? null,
                            'xDescUbicacion' => $detail['descripcionUbicacion'] ?? null,
                            'usuarioJuez' => $detail['usuarioJuez'] ?? null,
                            'juez' => $detail['nombreJuez'] ?? null,
                            'usuarioSecretario' => $detail['usuarioSecretario'] ?? null,
                            'secretario' => $detail['nombreSecretario'] ?? null,
                            'tipoExpediente' => $detail['tipoExpediente'] ?? null,
                            'parte' => $detail['parteNombreCompleto'] ?? null,
                            'indTipoParte' => $detail['tipoParte'] ?? null,
                            'xDescParte' => $detail['descTipoParte'] ?? null,
                            'chatId' => $mainConsulta->chatId,
                            // --- CAMPOS DE FECHA Y HORA ACTUALES ---
                            'regDate' => now()->toDateString(),
                            'regDatetime' => now(),
                            'regTimestamp' => now()->timestamp,
                        ]);
                    }
                    
                    // 4. Actualizar MainConsulta a "Encontrado"
                    $mainConsulta->status = 2; // 2 -> Encontrado/Procesado
                } else {
                    // Actualizar MainConsulta a "No Encontrado"
                    $mainConsulta->status = 3; // 3 -> No Encontrado
                }

                // Guardar el cambio de estado de la consulta principal
                $mainConsulta->save();
            });

            return response()->json([
                'success' => true,
                'message' => 'Consulta con ID ' . $data['id'] . ' procesada y actualizada exitosamente.'
            ], 200);

        } catch (\Exception $e) {
            // En caso de error, la transacción hará un rollback automático
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la consulta con ID ' . $data['id'],
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}

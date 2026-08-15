<?php

namespace App\Http\Controllers;

use App\Models\MainConsulta;
use App\Models\CabExpediente;
use App\Models\PartesExp;
use App\Models\DetailsExpediente;
use App\Models\EscritosExp;
use App\Models\AudienciasExp;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ApiController extends Controller
{
    public function getPendingConsultas(): JsonResponse
    {
        try {
            // Realiza la consulta a la base de datos usando el modelo de Eloquent.
            $consultasPendientes = MainConsulta::where('status', 1)
                                               ->where('step', 1)
                                               ->whereNotNull('message')
                                               ->get();

            // Retorna una respuesta JSON estándar con los datos.
            return response()->json([
                'success' => true,
                'itemFound' => $consultasPendientes->count() > 0,
                'data'    => $consultasPendientes,
                'message' => 'Se recuperaron ' . $consultasPendientes->count() . ' consultas pendientes.'
            ], 200);

        } catch (\Throwable $e) {
            // En caso de un error en la base de datos u otro problema,
            // se devuelve una respuesta de error del servidor.
            //
            // No se puede usar $consultasPendientes aquí: si el try falló, la
            // variable no existe y el propio catch termina fallando, ocultando
            // la causa real del problema.
            Log::error('Error al recuperar las consultas pendientes: ' . $e->getMessage(), [
                'archivo' => $e->getFile(),
                'linea' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'itemFound' => false,
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
            // Escritos y audiencias son opcionales: si la consulta al SIJ falla, el
            // microservicio envía el resto de la información igual y el bot responde
            // "no disponible" solo en esas opciones, en vez de quedarse sin respuesta.
            'listEscritos' => 'nullable|array',
            'listAudiencias' => 'nullable|array',
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
                            // Descripciones de sede y especialidad e incidente, para la
                            // opción "Información General" del bot.
                            'xDescSede' => $detail['descSede'] ?? null,
                            'xDescEspecialidad' => $detail['descEspecialidad'] ?? null,
                            'nIncidente' => $detail['nincidente'] ?? null,
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

                    // 4. Poblar EscritosExp y AudienciasExp
                    $nUnico = $data['cabExpedienteChat']['nunico'] ?? null;

                    $this->guardarEscritos($data, $mainConsulta, $nUnico);
                    $this->guardarAudiencias($data, $mainConsulta, $nUnico);

                    // 5. Actualizar MainConsulta a "Encontrado"
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

    /**
     * Guarda los escritos del expediente para este chat.
     *
     * Antes de insertar se borran los registros previos del mismo chatId y expediente:
     * un usuario puede consultar dos veces el mismo expediente y, sin este borrado, los
     * escritos se acumularían duplicados (el microservicio envía hasta 50 en cada envío).
     */
    private function guardarEscritos(array $data, MainConsulta $mainConsulta, $nUnico)
    {
        $escritos = $data['listEscritos'] ?? [];

        if (!is_array($escritos)) {
            return;
        }

        EscritosExp::where('chatId', $mainConsulta->chatId)
            ->where('nUnico', $nUnico)
            ->delete();

        foreach ($escritos as $escrito) {
            EscritosExp::create([
                'nUnico' => $escrito['nunico'] ?? $nUnico,
                'xFormato' => $escrito['numeroExpediente'] ?? null,
                'nIncidente' => $escrito['nincidente'] ?? null,
                'xNomInstancia' => $escrito['instancia'] ?? null,
                'especialista' => $escrito['especialista'] ?? null,
                'nroEscrito' => $escrito['nroEscrito'] ?? null,
                'fEscrito' => $escrito['fechaEscrito'] ?? null,
                'fAtencion' => $escrito['fechaAtencion'] ?? null,
                'xResolucion' => $escrito['resolucion'] ?? null,
                'xSumilla' => $escrito['sumilla'] ?? null,
                'xNombreArchivo' => $escrito['nombreArchivo'] ?? null,
                'chatId' => $mainConsulta->chatId,
                // --- CAMPOS DE FECHA Y HORA ACTUALES ---
                'regDate' => now()->toDateString(),
                'regDatetime' => now(),
                'regTimestamp' => now()->timestamp,
            ]);
        }
    }

    /**
     * Guarda las audiencias del expediente para este chat.
     *
     * Realizadas y programadas llegan en una sola lista, diferenciadas por 'tipoAudiencia'
     * ('REAL' / 'PROG'). Mismo criterio de borrado previo que en guardarEscritos().
     */
    private function guardarAudiencias(array $data, MainConsulta $mainConsulta, $nUnico)
    {
        $audiencias = $data['listAudiencias'] ?? [];

        if (!is_array($audiencias)) {
            return;
        }

        AudienciasExp::where('chatId', $mainConsulta->chatId)
            ->where('nUnico', $nUnico)
            ->delete();

        foreach ($audiencias as $audiencia) {
            AudienciasExp::create([
                'nUnico' => $audiencia['nunico'] ?? $nUnico,
                'xFormato' => $audiencia['numeroExpediente'] ?? null,
                'nIncidente' => $audiencia['nincidente'] ?? null,
                'xNomInstancia' => $audiencia['instancia'] ?? null,
                'especialista' => $audiencia['especialista'] ?? null,
                'indTipoAudiencia' => $audiencia['tipoAudiencia'] ?? null,
                'nProgramacion' => $audiencia['nprogramacion'] ?? null,
                'nSala' => $audiencia['nsala'] ?? null,
                'lEstado' => $audiencia['estado'] ?? null,
                'xDescAudiencia' => $audiencia['descripcionAudiencia'] ?? null,
                'fAudiencia' => $audiencia['fechaAudiencia'] ?? null,
                'xArchivoActa' => $audiencia['archivoActa'] ?? null,
                'xArchivoAudio' => $audiencia['archivoAudio'] ?? null,
                'xEnlace' => $audiencia['enlace'] ?? null,
                'chatId' => $mainConsulta->chatId,
                // --- CAMPOS DE FECHA Y HORA ACTUALES ---
                'regDate' => now()->toDateString(),
                'regDatetime' => now(),
                'regTimestamp' => now()->timestamp,
            ]);
        }
    }
}

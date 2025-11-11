<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Participante;
use App\Models\ParticipanteOferta;
use App\Models\OfertaDisciplina;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ValidacionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (auth()->user()->rol !== 'Administrador') {
                abort(403, 'Acceso denegado.');
            }
            return $next($request);
        });
    }

    // Mostrar solicitudes
    public function index(Request $request)
    {
        $estado = $request->get('estado');
        $disciplina = $request->get('disciplina');

        $query = ParticipanteOferta::with(['participante', 'oferta.disciplina'])
            ->when($estado, fn($q) => $q->where('estado', $estado))
            ->when($disciplina, fn($q) =>
                $q->whereHas('oferta.disciplina', fn($d) =>
                    $d->where('nombre', $disciplina)
                )
            )
            ->orderBy('created_at', 'desc');

        $solicitudes = $query->get();

        return view('validaciones.index', compact('solicitudes'));
    }

    // ✅ Aprobar solicitud
    public function aprobar($id)
    {
        DB::beginTransaction();

        try {
            // Bloquear el registro para evitar condiciones de carrera
            $solicitud = ParticipanteOferta::with('oferta')
                ->lockForUpdate()
                ->findOrFail($id);

            $oferta = $solicitud->oferta;

            // Recalcular cupos disponibles solo con inscripciones aprobadas
            $cupos = $oferta->cuposDisponibles();

            if ($cupos <= 0) {
                DB::rollBack();

                // Eliminar la solicitud del participante porque ya no hay lugar
                $solicitud->delete();

                return response()->json([
                    'mensaje' => '⚠️ No hay cupos disponibles en esta disciplina. El participante deberá seleccionar otra.'
                ]);
            }

            // Aprobar inscripción
            $solicitud->update([
                'estado' => 'aprobada',
                'motivo_rechazo' => ''
            ]);

            DB::commit();

            return response()->json([
                'mensaje' => '✅ Inscripción aprobada correctamente. Cupos restantes: ' . ($cupos - 1)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['mensaje' => 'Error al aprobar: ' . $e->getMessage()], 500);
        }
    }

    // ❌ Rechazar solicitud
    public function rechazar(Request $request, $id)
    {
        $solicitud = ParticipanteOferta::findOrFail($id);
        $motivo = $request->motivo ?? 'Sin especificar';

        $solicitud->update([
            'estado' => 'rechazada',
            'motivo_rechazo' => $motivo
        ]);

        return response()->json([
            'mensaje' => '❌ La inscripción fue rechazada. Motivo: ' . $motivo
        ]);
    }

    // 📄 Ver documentos
    public function documentos($id)
    {
        $participante = Participante::findOrFail($id);

        return response()->json([
            'fotografia' => Storage::url($participante->fotografia_path),
            'constancia' => Storage::url($participante->constancia_laboral_path),
            'comprobante' => Storage::url($participante->comprobante_pago_path),
        ]);
    }
}

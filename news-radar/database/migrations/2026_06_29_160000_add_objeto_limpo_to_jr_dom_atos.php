<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOM/SC — objeto legível (ADITIVO, ISOLADO).
 *
 * O `objeto` cru começa com boilerplate institucional ("ESTADO DE SANTA
 * CATARINA PREFEITURA MUNICIPAL DE X ... CNPJ ... torna público ..."), inútil
 * nas 3 páginas. Estes dois campos guardam a versão LEGÍVEL:
 *   - objeto_limpo: o que está sendo comprado/contratado/feito (heurística pra
 *     TODOS os atos; Sonnet poli pros pontuados do radar).
 *   - gancho_curto: hook de ≤8 palavras (Sonnet, só radar) — provocativo, mas
 *     é LEAD, NUNCA acusação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jr_dom_atos', function (Blueprint $table) {
            $table->text('objeto_limpo')->nullable()->after('objeto');
            $table->string('gancho_curto')->nullable()->after('gancho');
        });
    }

    public function down(): void
    {
        Schema::table('jr_dom_atos', function (Blueprint $table) {
            $table->dropColumn(['objeto_limpo', 'gancho_curto']);
        });
    }
};

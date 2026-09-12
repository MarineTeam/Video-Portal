<?php

/**
 * Spanish.
 *
 * # READ THIS BEFORE A CONGREGATION RELIES ON IT
 *
 * These translations have NOT been reviewed by a native speaker. They are short
 * interface strings and they are careful, but "careful" is not the same as
 * "checked", and this file is stated as unverified rather than presented as
 * finished — which is the same rule this project applied to a cryptographic
 * test vector written from memory: something nobody can source is worse than
 * nothing, because it looks like evidence.
 *
 * What that means in practice: a site running in Spanish is usable today, and
 * the first Spanish-speaking person who reads a screen will find something to
 * correct. Both of those are fine. What would not be fine is shipping this as
 * though it had been reviewed.
 *
 * Two choices worth naming, because a reviewer will have an opinion and should
 * know they were deliberate rather than accidental:
 *
 *   "En vivo" rather than "En directo" for live. Both are correct; the first is
 *   the broader of the two across Spanish-speaking countries.
 *
 *   "Videos" without the accent, which is the Latin American spelling. Spain
 *   writes "vídeos". A site wanting the other can copy this file to `es-ES.php`
 *   and change it — the locale matcher will hand `es-ES` to a browser asking
 *   for Spain and fall back to this file for everybody else.
 *
 * Every key in en.php is present here, and a test enforces that rather than
 * trusting it. See LocaleCompletenessTest.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // ------------------------------------------------------------ navigation
    'nav.home'        => 'Inicio',
    'nav.library'     => 'Biblioteca',
    'nav.search'      => 'Buscar',
    'nav.live'        => 'En vivo',
    'nav.calendar'    => 'Calendario',
    'nav.events'      => 'Eventos',
    'nav.groups'      => 'Grupos pequeños',
    'nav.prayer'      => 'Oración',
    'nav.books'       => 'Libros',
    'nav.account'     => 'Cuenta',
    'nav.sign_in'     => 'Iniciar sesión',
    'nav.sign_out'    => 'Cerrar sesión',
    'nav.admin'       => 'Administración',
    'nav.menu'        => 'Menú',
    'nav.skip'        => 'Ir al contenido',

    // ---------------------------------------------------------------- actions
    'action.save'     => 'Guardar',
    'action.cancel'   => 'Cancelar',
    'action.send'     => 'Enviar',
    'action.back'     => 'Atrás',
    'action.next'     => 'Siguiente',
    'action.more'     => 'Más',
    'action.play'     => 'Reproducir',
    'action.watch'    => 'Ver',
    'action.download' => 'Descargar',
    'action.share'    => 'Compartir',
    'action.remove'   => 'Quitar',
    'action.sign_in'  => 'Iniciar sesión',

    // ---------------------------------------------------------- the account
    'account.title'        => 'Tu cuenta',
    'account.saved'        => 'Guardados',
    'account.offline'      => 'Guardado sin conexión',
    'account.history'      => 'Tus datos',
    'account.notifications' => 'Notificaciones',
    'account.messages'     => 'Mensajes',
    'account.password'     => 'Contraseña',
    'account.television'   => 'Televisión',
    'account.shared_links' => 'Enlaces que has compartido',
    'account.reminders'    => 'Recordatorios',

    // ------------------------------------------------------------- the library
    'library.continue'     => 'Seguir viendo',
    'library.latest'       => 'Lo más reciente',
    'library.featured'     => 'Destacados',
    'library.most_watched' => 'Lo más visto',
    'library.related'      => 'Contenido similar',
    'library.series'       => 'Series',
    'library.speakers'     => 'Predicadores',
    'library.nothing'      => 'Aquí todavía no hay nada.',
    'library.members_only' => 'Solo para miembros',
    'library.count_videos' => 'Videos: :count',

    // ------------------------------------------------------------ the language
    'language.label'  => 'Idioma',
    'language.choose' => 'Elige un idioma',
    'language.spoken' => 'Hablado en :language',

    // -------------------------------------------------------------- the times
    'time.today'     => 'Hoy',
    'time.tomorrow'  => 'Mañana',
    'time.yesterday' => 'Ayer',

    // ------------------------------------------------------------- the refusals
    'error.not_found'   => 'No hay nada en esa dirección.',
    'error.forbidden'   => 'Esto no es para ti.',
    'error.sign_in'     => 'Inicia sesión para ver esto.',
    'error.members_only' => 'Esto es para miembros.',
    'error.went_wrong'  => 'Algo ha ido mal. Inténtalo de nuevo.',
];

<?php
declare(strict_types=1);
namespace {
    /** Test process only; default delegates to the real clock and is reset per test. */
    final class PrintifySyntheticLeaseClock { public static ?int $now=null; }
}
namespace DigiForge\POD {
    function time():int { return \PrintifySyntheticLeaseClock::$now ?? \time(); }
}

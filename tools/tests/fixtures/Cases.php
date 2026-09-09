<?php
class Cases
{
    public function guarded($ch)                    // NO debe reportar
    {
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
    }
    public function guardedNested($ch)              // NO: anidado dentro de la guarda
    {
        if (PHP_VERSION_ID < 80000) {
            foreach (array(1) as $x) {
                curl_close($ch);
            }
        }
    }
    public function afterGuard($ch)                 // SI: fuera de la guarda ya cerrada
    {
        if (PHP_VERSION_ID < 80000) {
            $noop = 1;
        }
        curl_close($ch);
    }
    public function arrayKey($v)                    // NO: clave de array, no llamada
    {
        return array('money_format' => $v, 'strftime' => 1);
    }
    public function methodOfSameName($o)            // NO: metodo, no funcion global
    {
        return $o->each(1) + Foo::each(2);
    }
    public function bareCall($s)                    // SI
    {
        return utf8_encode($s);
    }
    public function constantUse($s)                 // SI: constante deprecada
    {
        return filter_var($s, FILTER_SANITIZE_STRING);
    }
    public function unbracedGuard($ch)              // SI: la guarda sin llaves no cubre la linea siguiente
    {
        if (PHP_VERSION_ID < 80000) $noop = 1;
        curl_close($ch);
    }
    public function stringMention()                 // NO: dentro de un literal
    {
        return 'curl_close is not called here';
    }
    public function guardedWithInterpolation($ch, $y)   // NO: la interpolacion no debe romper la guarda
    {
        if (PHP_VERSION_ID < 80000) {
            $note = "closing handle for {$y}";
            curl_close($ch);
        }
        return isset($note) ? $note : '';
    }
    public function interpolationThenBareCall($ch, $y)  // SI: la interpolacion no debe tapar una desnuda
    {
        $note = "about to close for {$y}";
        curl_close($ch);
        return $note;
    }
}

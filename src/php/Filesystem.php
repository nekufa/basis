<?php

namespace Basis;

class Filesystem
{
    private $root;
    private $framework;

    function __construct($root)
    {
        $this->root = $root;
    }

    function exists()
    {
        $path = call_user_func_array([$this, 'getPath'], func_get_args());
        return is_dir($path) || file_exists($path);
    }

    function getPath()
    {
        if (func_get_args()) {
            $chain = func_get_args();
            array_unshift($chain, $this->root);
            foreach ($chain as $k => $v) {
                if (!strlen($v)) {
                    unset($chain[$k]);
                }
            }
            return implode(DIRECTORY_SEPARATOR, array_values($chain));
        }

        return $this->root;
    }
}

<?php

namespace Floxim\Floxim\Field;
use Floxim\Floxim\System\Fx as fx;

class String extends Baze {

    public function format_settings() {
        $fields = array(
            array(
                'type' => 'checkbox', 
                'name' => 'html', 
                'label' => fx::alang('allow HTML tags','system')
            )
        );
        return $fields;
    }

    public function get_sql_type() {
        return "VARCHAR(255)";
    }
}
<?php

class WP_List_Table
{
    public $items = [];
    protected $_pagination_args = [];
    protected $_column_headers = [];

    public function __construct($args = [])
    {
    }

    public function set_pagination_args($args)
    {
        $this->_pagination_args = $args;
    }

    public function get_pagination_args()
    {
        return $this->_pagination_args;
    }

    public function get_pagenum()
    {
        return (isset($_REQUEST['paged']) && (int) $_REQUEST['paged'] > 0)
            ? (int) $_REQUEST['paged']
            : 1;
    }

    public function views()
    {
        if (!method_exists($this, 'get_views')) {
            return;
        }

        $views = $this->get_views();

        if (empty($views)) {
            return;
        }

        echo implode('', $views);
    }

    public function display()
    {
        // Intentionally left blank for tests.
    }

    public function current_action()
    {
        return false;
    }
}

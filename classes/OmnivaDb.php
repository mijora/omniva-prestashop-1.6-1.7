<?php

class OmnivaDb
{
    const SQL_GUARD = '89485368X846aModer416xa1656ax1';

    const TABLES = [
        'omniva_order',
        'omniva_cart_terminal',
        'omniva_order_history',
        'omniva_product'
    ];
    /**
     * Create tables for module
     */
    public function createTables()
    {
        $sql_path = dirname(__FILE__) . '/../sql/';
        $sql_files = scandir($sql_path);
        $sql_queries = [];
        foreach($sql_files as $sql_file)
        {
            $file_parts = pathinfo($sql_file);
            if($file_parts['extension'] == 'sql')
            {
                $sql_file = file_get_contents($sql_path . $sql_file);
                $sql_parts = explode(self::SQL_GUARD, $sql_file);

                // don't scream, just ignore sql, if it does not have token
                if(count($sql_parts) != 2 || (isset($sql_parts[1]) && $sql_parts[1] !== ''))
                    continue;

                $sql_query = str_replace('_DB_PREFIX_', _DB_PREFIX_, $sql_parts[0]);
                $sql_queries[] = str_replace('_MYSQL_ENGINE_', _MYSQL_ENGINE_, $sql_query);
            }
        }
        foreach ($sql_queries as $query) {
            try {
                $res_query = Db::getInstance()->execute($query);

                if ($res_query === false) {
                    return false;
                }
            } catch (Exception $e) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete module tables
     */
    public function deleteTables()
    {
        foreach (self::TABLES as $table) {
            try {
                $res_query = Db::getInstance()->execute("DROP TABLE IF EXISTS " . _DB_PREFIX_ . $table);
            } catch (Exception $e) {
            }
        }

        return true;
    }

}

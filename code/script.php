<?php

    /**
     * Function that saves the start time of the script in the database log table
     *
     * @returns void
     */
    function logStart() {
        $db = $GLOBALS['db'];

        $date = date("Y-m-d H:i:s");
        $sql = "INSERT INTO log_script VALUES (0, '{$date}', NULL)";

        $db->query($sql);
        $GLOBALS['log_id'] = $db->insert_id;
    }

    /**
     * Function that saves the end time of the script in the database log table
     *
     * @returns void
     */
    function logEnd() {
        $db = $GLOBALS['db'];
        $log_id = $GLOBALS['log_id'];

        $date = date("Y-m-d H:i:s");
        $sql = "UPDATE log_script SET data_termino = '{$date}' WHERE id = '{$log_id}'";

        $db->query($sql);
    }

    /**
     * Function that searches the publication string for keywords that correspond
     * to actions (alimentos, divorcio, investigação de paternidade, inventário)
     *
     * @param string $string publication string
     * @param array $words = array of words to be searched
     *
     * @returns boolean
     */
    function searchWordsRecord($string, $words) {
        foreach ($words as $word) {
            if (strpos($string, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Function that classifies a record, creates the attribute "nome do juiz" and "número do processo"
     *
     * @param string $type register type (alimentos, divorcio, investigação de paternidade, inventário)
     * @param string $string publication string
     *
     * @returns void
     */
    function classificateRecord($type, $string) {
        $record = new stdClass();
        $record->numero_processo = substr($string, strpos($string, "Processo ")+9, 25);
        $nome_juiz_start = strpos($string, "JUIZ(A) DE DIREITO ")+19;
        $nome_juiz_end = strpos($string, "ESCRIVÃ(O) JUDICIAL")-2;
        $record->nome_juiz = substr($string, $nome_juiz_start, $nome_juiz_end-$nome_juiz_start);
        $record->publicacao = $string;

        $GLOBALS['records']->$type[] = $record;
    }

    /**
     * Function that sorts a records group by "número do processo" or "nome do juiz"
     *
     * @param string $groyp which records group should be sorted (alimentos, divorcio, paternidade, inventario, outros)
     * @param string $sort_by sort by "numero_processo" or "nome_juiz"
     *
     * @returns void
     */
    function sortRecords($group, $sort_by) {
        $data = $GLOBALS['records']->$group;

        if ($sort_by === 'numero_processo') {
            usort($data,
                function($a, $b) {
                    if($a->numero_processo == $b->numero_processo) return 0;
                    return (($a->numero_processo < $b->numero_processo) ? -1 : 1 );
                }
            );
        }
        else if ($sort_by === 'nome_juiz') {
            usort($data,
                function($a, $b) {
                    if($a->nome_juiz == $b->nome_juiz) return 0;
                    return (($a->nome_juiz < $b->nome_juiz) ? -1 : 1 );
                }
            );
        }

        $GLOBALS['records']->$group = $data;
    }

    /**
     * Function that creates a json file
     *
     * @param string $content file content
     * @param string $file_name file directory
     *
     * @returns void
     */
    function createJsonFile($content, $file_name) {
        $file = fopen($file_name, 'w');
        fwrite($file, $content);
        fclose($file);
    }

    /**
     * Function that creates the content of the files and call createJsonFile()
     *
     * @returns void
     */
    function createFiles() {
        $classifications = array_keys($GLOBALS['search']);

        //Creates the first set of jsons, separated by type of actions and ordered by nome_juiz
        foreach ($classifications as $classification) {
            sortRecords($classification, 'nome_juiz');
            createJsonFile(json_encode($GLOBALS['records']->$classification), strtoupper($classification) . '.json');
        }

        //Creates the first set of jsons, separated by type of actions and judge name and ordered by numero_processo
        foreach ($classifications as $classification) {
            sortRecords($classification, 'numero_processo');
            $juizes = getJuizes($classification);
            foreach ($juizes as $key => $item) {
                createJsonFile(json_encode($item), strtoupper($classification) . '_' . strtoupper($key) . '.json');
            }
        }

    }

    /**
     * Auxiliary function to separate records by type of action and name of judge
     *
     * @param string $classification type of action (alimentos, divorcio, paternidade, inventario, outros)
     *
     * @returns array Name of judges and cases for which they are responsible
     */
    function getJuizes($classification) {
        $juizes = array();
        foreach ($GLOBALS['records']->$classification as $key => $item) {
            if (!array_key_exists($item->nome_juiz, $juizes)) {
                $juizes[$item->nome_juiz] = array($item);
            }
            else {
                $juizes[$item->nome_juiz][] = $item;
            }
        }

        return $juizes;
    }

    //Database connection
    $db = new mysqli("127.0.0.1", "root", "", "teste");
    $log_id = 0;

    //Save the script start time in log
    logStart();

    //This global variable will store the extracted records
    $records = new stdClass();
    $records->alimentos = $records->divorcio = $records->paternidade = $records->inventario = $records->outros = array();

    //Define the type actions and the keywords to search in the publications string
    $search = array();
    $search['alimentos'] = array('- Alimentos -', '- Execução de Alimentos -', '- Cumprimento de Sentença de
    Obrigação de Prestar Alimentos -');
    $search['divorcio'] = array('- Divórcio Consensual -');
    $search['paternidade'] = array('- Investigação de Paternidade -');
    $search['inventario'] = array('- Inventário -', 'Inventário e Partilha -');

    //Get the publications from "4ª Vara da Família e Sucessões" in database
    $sql = "SELECT * 
            FROM publicacoes_fila_2020_08_02 
            WHERE (INSTR(ra_conteudo, '4ª Vara da Família e Sucessões') > 0)";
    $query = $db->query($sql);

    while ($data = $query->fetch_array()) {
        //Flag to find out if the record was classified in any type
        $found = false;

        //Search in the publication string for the keywords
        foreach($search as $key => $item) {
            //If found, then classifies the record according to the word found
            if (searchWordsRecord($data['ra_conteudo'], $item)) {
                classificateRecord($key, $data['ra_conteudo']);
                $found = true;
                break;
            }
        }

        //If no keywords were found, classifies as other
        if (!$found) {
            classificateRecord('outros', $data['ra_conteudo']);
        }
    }

    //Add others to search because search is used to create the files
    $search['outros'] = array('');

    //Create the json files
    createFiles();

    //Save the script end time in log
    logEnd();

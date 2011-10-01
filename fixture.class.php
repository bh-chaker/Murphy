<?php
    namespace murphy;
    use Exception,Closure,Args;
    
    class Fixture
    {
        private $callbacks;
        private $data;
        private static $instance = NULL;
        
        private function __construct()
        {
            $this->callbacks = array();
            $this->data = array();
        }
        
        private static function instance()
        {
            if(self::$instance === NULL)
                self::$instance = new Fixture();
            
            return self::$instance;
        }
        
        public static function add($name,Closure $callback)
        {
            
            if(isset(self::instance()->callbacks[$name]))
                throw new DuplicateFixtureException('You have already added a fixture called: '.$name);
            
            self::instance()->callbacks[$name] = $callback;
        }
        
        public function execute(Closure $db_connect = NULL)
        {
            $databases = array();

            foreach($this->data as $fixture => $d)
            {
                if(isset($d['tables']))
                {
                    if(!isset($d['database']))
                        throw new InvalidFixtureFormatException('You have included a @tables directive for '.$fixture.' but no @database directive');
                    
                    if(!isset($databases[$d['database']]))
                        $databases[$d['database']] = array();
                        
                    $databases[$d['database']] = array_merge($databases[$d['database']],$d['tables']);
                }
            }
            
            $aliases  = array();

            if(count($databases))
            {
                if(!$mysql_root = Args::get('mysql_root',Args::argv))
                    exit(1);

                foreach($databases as $database => $tables)
                {
                    mysql_connect('localhost','root',$mysql_root);
                    mysql_select_db($database);
                    $tables = array_unique($tables);
                    $create_table_statements = array();
                    
                    foreach($tables as $table)
                    {
                        $row = mysql_fetch_assoc(mysql_query('SHOW CREATE TABLE `'.$table.'`'));
                        $create_table_statements[] = $row['Create Table'];
                    }

                    $alias = md5($database);
                    $aliases[$database] = array('localhost',
                                                'root',
                                                $mysql_root,
                                                md5($database));

                    mysql_query('DROP DATABASE IF EXISTS '.$alias) or die(mysql_error());
                    mysql_query('CREATE DATABASE '.$alias) or die(mysql_error());
                    mysql_select_db($alias);
                    
                    foreach($create_table_statements as $stmt)
                        mysql_query($stmt) or die(mysql_error());
                }
                
                if(!$db_connect instanceof Closure)
                    throw new DbFixtureConnectionException('You have included database fixtures without a callback to pass connection details to');
                
                $db_connect($aliases);
            }

            foreach($this->data as $fixture_name => $d)
            {
                $args = array();

                foreach($d['rows'] as $row)
                {
                    foreach($row as $index => $line)
                        $args[$d['header'][$index]] = $line;

                    self::instance()->callbacks[$fixture_name]($args);
                }
            }
            
            self::$instance = NULL;
        }

        public function also($file)
        {
            $this->extractFixtureDataFromFile($file);
            return $this;
        }
        
        public static function load($file)
        {
            self::instance()->extractFixtureDataFromFile($file);
            return self::instance();
        }
        
        private function extractFixtureDataFromFile($file)
        {
            $path = PACKAGES_DIR.'/'.$file;
            require_once($path);
            $contents = file($path,FILE_IGNORE_NEW_LINES);
            $docblocks = array();
            $cur_docblock = NULL;
            $previous_docblock = NULL;
        
            foreach($contents as $cont)
            {
                if(strpos($cont,'/**') !== FALSE)
                    $cur_docblock = array();
        
                if(strpos($cont,'*/') !== FALSE)
                {
                    $previous_docblock  = $cur_docblock;
                    $cur_docblock       = NULL;
                }
                
                if($cur_docblock !== NULL)
                    $cur_docblock[] = $cont;
                else if(preg_match('/murphy\\\\Fixture::add\(\'(.*)\'/U',$cont,$matches))
                    $docblocks[$matches[1]] = $previous_docblock;
            }
        
            foreach($docblocks as $fixture_name => $block)
            {
                $this->data[$fixture_name] = array('rows' => array());
                $database = 'non_db_fixture_'.microtime(true).rand(0,9999);

                foreach($block as $b)
                {
                    if(strpos(trim($b),'/*') !== 0)
                    {
                        $b = trim(str_replace('*','',$b));
                        
                        if(strpos($b,'@database ') === 0)
                            $database = trim(str_replace('@database','',$b));
                        else if(strpos($b,'@tables ') === 0)
                            $this->data[$fixture_name]['tables'] = explode(',',trim(str_replace('@tables','',$b)));
                        else if(!isset($this->data[$fixture_name]['header']))
                            $this->data[$fixture_name]['header'] = array_map('trim',explode('|',$b));
                        else
                            $this->data[$fixture_name]['rows'][] = array_map('trim',explode('|',$b));
                    }
                }
                
                if(!$database)
                    throw new InvalidFixtureFormatException('You must specify the @database directive for fixture: '.$fixture_name.' in: '.$file);
                    
                $this->data[$fixture_name]['database'] = $database;
            }
        }
    }

    class DuplicateFixtureException extends Exception{}
    class InvalidFixtureFormatException extends Exception{}
    class DbFixtureConnectionException extends Exception{}
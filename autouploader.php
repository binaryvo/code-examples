<?php
//error_reporting(E_ALL);
//ini_set('display_errors', 1);
set_time_limit(0);
ini_set('max_execution_time', 0);

require('libs/Curl.php');
require('importPosts_Exception.php');
require_once('include/ApplicationLogger.php');

use Curl\Curl;

/**
 * WP Auto Importer of Posts through 
 * WP Ultimate CSV Importer
 * Example of calling:
 * $obj = new importPosts('http://localhost/wordpress/', 'admin', 'admin', 'upload/soa-98.csv');
 * $obj->startProcess();
 */
class importPosts
{
    const ENABLE_ERROR_LOG = true;

    const LOGIN_PAGE = 'wp-login.php';
    const LOGOUT_PAGE = 'wp-login.php?action=logout';
    const ULTIMATE_CSV_IMPORTER_UPLOAD_PAGE = 'wp-admin/admin.php?page=wp-ultimate-csv-importer/index.php&__module=post&step=uploadfile';
    const ULTIMATE_CSV_IMPORTER_MAPPING_SETTINGS_PAGE = 'wp-admin/admin.php?page=wp-ultimate-csv-importer/index.php&__module=post&step=mapping_settings';
    const ULTIMATE_CSV_IMPORTER_IMPORT_OPTIONS_PAGE = 'wp-admin/admin.php?page=wp-ultimate-csv-importer/index.php&__module=post&step=importoptions';
    const ULTIMATE_CSV_IMPORTER_IMPORT_UPLOADER_PAGE = 'wp-content/plugins/wp-ultimate-csv-importer/lib/jquery-plugins/uploader.php?uploadPath=[uploadPath]&curr_action=post';
    
    const RECORDS_BY_STEP = 10;

    private $_siteDomain = '';
    private $_login = '';
    private $_password = '';
    private $_uploadFileLink = '';
    private $_sourceFileName = '';
    
    private $_uploaddir = '';
    private $_serverUploadFileName = '';
    private $_serverUploadCsvRealname = '';
    private $_serverCurrentFileVersion = '';    
    
    private $_uploadedHeader = '';
    private $_h1 = '';
    private $_h2 = '';
    private $_selectedImporter = '';
    private $_prevoptionindex = '';
    private $_prevoptionvalue = '';
    private $_totRecords = '';
    private $_tmpLoc = '';
    private $_stepstatus = '';
    private $_uploadedFile = '';
    private $_uploadedCsvName = '';
    private $_mappingArr = '';
    private $_mappingFieldsArray = '';
    
    private $_logoutUrl = null;
    
    private $_curl = null;


    /**
     * @param string $domain
     * @param string $login
     * @param string $password
     * @param string $uploadFileLink
     * @return \importPosts
     */
    public static function getInstance($domain, $login, $password, $uploadFileLink)
    {
        return new importPosts($domain, $login, $password, $uploadFileLink);
    }
    
    /**
     * @param string $domain
     * @param string $login
     * @param string $password
     * @param string $uploadFileLink
     */
    public function __construct($domain, $login, $password, $uploadFileLink)
    {
        if ((version_compare(phpversion(), '5.4.0', '>=') && session_status() === PHP_SESSION_NONE) || 
                (version_compare(phpversion(), '5.4.0', '<') && session_id() === '')) {
            session_start();
        }
        
        $this->logError("start import");
        
        // fix domain if no protocol transfered
        if ((strpos($domain, 'http://') === false) && (strpos($domain, 'https://') === false)) {
            $domain = 'http://' . $domain;
        }

        // add slash after domain if not in domain string
        if (substr($domain, strlen($domain)-1) != '/') {
            $domain .= '/';
        }
        
        $this->_siteDomain = $domain;
        $this->_login = $login;
        $this->_password = $password;
        $this->_uploadFileLink = $uploadFileLink;
        $this->_sourceFileName = basename($uploadFileLink);
        
        $this->_curl = new Curl();
        $this->_curl->setOpt(CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1");
        $this->_curl->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $this->_curl->setOpt(CURLOPT_MAXREDIRS, 10);
        $this->_curl->setOpt(CURLOPT_CONNECTTIMEOUT ,0);
        $this->_curl->setOpt(CURLOPT_TIMEOUT, 400);
        $this->_curl->setCookieJar('cookie.txt');
    }
    
    /**
     * Start proccess of Upload and Import posts
     */
    public function startProcess()
    {
        $this->loginTo();
        $this->getPluginsPage();
        $this->uploadFile();
        $this->mappingSettings();
        $this->importOptions();
        $this->import();
        $this->logout();
    }

    /**
     * Login to WP admin panel
     * @return boolean
     */
    protected function loginTo() 
    {
        $this->logError("loginTo");
        try {
            $this->_curl->setOpt(CURLOPT_URL, $this->_siteDomain . self::LOGIN_PAGE);
            $this->_curl->setOpt(CURLOPT_REFERER, $this->_siteDomain . "wp-admin");
            $this->_curl->post($this->_siteDomain . self::LOGIN_PAGE, array(
                'log' => $this->_login,
                'pwd' => $this->_password
            ));
            
            if ($this->_curl->error) {
                if ($this->_curl->response) {
                    throw new importPosts_Exception($this->_curl->response->error . ': ' . $this->_curl->response->error_description);
                } else {
                    throw new importPosts_Exception($this->_curl->curl_error_message);
                }
            }
        
            $DOM = new DOMDocument;
            libxml_use_internal_errors(true);
            $DOM->loadHTML($this->_curl->response);
            libxml_clear_errors();

            if (($DOM->getElementsByTagName('html')->length == 0) || 
                    ($DOM->getElementsByTagName('html')->item(0)->getAttribute('class') == 'wp-toolbar')) {
                ;
            } elseif ($DOM->getElementById('login_error') !== null) {
                throw new importPosts_Exception($DOM->getElementById('login_error')->nodeValue);
            }
            
            if (($logoutPanel = $DOM->getElementById('wp-admin-bar-logout')) !== null) {
                if (isset($logoutPanel->firstChild->getAttributeNode('href')->value)) {
                    $this->_logoutUrl = $logoutPanel->firstChild->getAttributeNode('href')->value;
                }
            }
            $this->logError("Logout Url: " . $this->_logoutUrl);
        } catch (importPosts_Exception $e) {
            echo "Error: " . $e->getMessage();
            $this->logError($e->getMessage());
            exit;
        }
    }
    
    /**
     * Go to plugins page to retrieve upploaddir value
     */
    protected function getPluginsPage()
    {
        $this->logError("getPluginsPage");
        try {
            $this->_curl->get($this->_siteDomain . self::ULTIMATE_CSV_IMPORTER_UPLOAD_PAGE);
        
            if ($this->_curl->error) {
                if ($this->_curl->response) {
                    throw new importPosts_Exception($this->_curl->response->error . ': ' . $this->_curl->response->error_description);
                } else {
                    throw new importPosts_Exception($this->_curl->curl_error_message);
                }
            }
        
            $DOM = new DOMDocument;
            libxml_use_internal_errors(true);
            $DOM->loadHTML($this->_curl->response);
            libxml_clear_errors();

            if ($DOM->getElementById('error-page')) {
                throw new importPosts_Exception('No WP Ultimate CSV Importer plugin installed or plugin folder is not default');
            }

            if (!$DOM->getElementById('uploaddir')) {
                throw new importPosts_Exception('Can\'t find uploaddir element');
            }
            
            $this->_uploaddir = $DOM->getElementById('uploaddir')->getAttribute('value');
            $this->logError("Upload Dir: " . $this->_uploaddir);
        } catch (importPosts_Exception $e) {
            echo "Error: " . $e->getMessage();
            $this->logError($e->getMessage());
            exit;
        }
    }
    
    /**
     * Upload file (1st step of plugin)
     */
    protected function uploadFile()
    {
        $this->logError("uploadFile " . realpath($this->_uploadFileLink));
        try {
            $this->_curl->setOpt(CURLOPT_CUSTOMREQUEST, 'POST');
            $this->_curl->setOpt(CURLOPT_REFERER, $this->_siteDomain . self::ULTIMATE_CSV_IMPORTER_UPLOAD_PAGE);
            $this->_curl->post($this->_siteDomain . str_replace('[uploadPath]', $this->_uploaddir, self::ULTIMATE_CSV_IMPORTER_IMPORT_UPLOADER_PAGE),
                    array('_method' => 'post',
                          'current_module' => 'post',
                          'files' => '@' . realpath($this->_uploadFileLink)
                        )
                    );
        
            if ($this->_curl->error) {
                if ($this->_curl->response) {
                    throw new importPosts_Exception($this->_curl->response->error . ': ' . $this->_curl->response->error_description);
                } else {
                    throw new importPosts_Exception($this->_curl->curl_error_message);
                }
            }
            
            $this->logError("response: " . $this->_curl->response);

            $response = json_decode($this->_curl->response);

            if (!$response) {
                $this->logError($response);
                throw new importPosts_Exception('Can\'t parse file upload response. Probably response is wrong');
            }
            
            $this->_serverUploadFileName = $response->files[0]->name;
        
            list($fileWithModule) = explode(".csv", $response->files[0]->uploadedname);

            $this->_serverUploadCsvRealname = $fileWithModule . "-post" . ".csv";

            $versionPart1 = explode('post', $response->files[0]->name);
            $versionPart2 = explode('.csv', $versionPart1[1]);
            $versionPart3 = explode('-', $versionPart2[0]);

            $this->_serverCurrentFileVersion = $versionPart3[1];
            
            $this->logError("_serverCurrentFileVersion: " . $versionPart3[1]);
        } catch (importPosts_Exception $e) {
            echo "Error: " . $e->getMessage();
            $this->logError($e->getMessage());
            exit;
        }
    }
    
    /**
     * Go to Mapping Settings page of Plugin
     */
    protected function mappingSettings()
    {        
        $this->logError("mappingSettings");
        try {
            //$this->_curl->setOpt(CURLOPT_CUSTOMREQUEST, null);
            $this->_curl->post($this->_siteDomain . self::ULTIMATE_CSV_IMPORTER_MAPPING_SETTINGS_PAGE,
                    array('pluginurl' => $this->_siteDomain . "wp-content",
                          'uploaddir' => $this->_uploaddir,
                          'uploadfilename' => $this->_serverUploadFileName,
                          'uploadedfilename' => $this->_serverUploadCsvRealname,
                          'upload_csv_realname' => $this->_serverUploadCsvRealname,
                          'current_file_version' => $this->_serverCurrentFileVersion,
                          'current_module' => 'post'
                        )
                    );

            if ($this->_curl->error) {
                if ($this->_curl->response) {
                    throw new importPosts_Exception($this->_curl->response->error . ': ' . $this->_curl->response->error_description);
                } else {
                    throw new importPosts_Exception($this->_curl->curl_error_message);
                }
            }

            $DOM = new DOMDocument;
            libxml_use_internal_errors(true);
            $DOM->loadHTML($this->_curl->response);
            libxml_clear_errors();

            //// set variables
            $this->_uploadedHeader = $DOM->getElementById('imploded_header')->getAttribute('value');
            $this->_h1 = $DOM->getElementById('h1')->getAttribute('value');
            $this->_h2 = $DOM->getElementById('h2')->getAttribute('value');
            $this->_selectedImporter = $DOM->getElementById('selectedImporter')->getAttribute('value');
            $this->_prevoptionindex = $DOM->getElementById('prevoptionindex')->getAttribute('value');
            $this->_prevoptionvalue = $DOM->getElementById('prevoptionvalue')->getAttribute('value');
            $this->_totRecords = $DOM->getElementById('totRecords')->getAttribute('value');
            $this->_tmpLoc = $DOM->getElementById('tmpLoc')->getAttribute('value');
            $this->_stepstatus = $DOM->getElementById('stepstatus')->getAttribute('value');
            $this->_uploadedFile = $DOM->getElementById('uploadedFile')->getAttribute('value');
            $this->_uploadedCsvName = $DOM->getElementById('uploaded_csv_name')->getAttribute('value');
            $this->_mappingArr = $DOM->getElementById('mappingArr')->getAttribute('value');
            $this->_mappingFieldsArray = $DOM->getElementById('mapping_fields_array')->getAttribute('value');     
            
            $this->logError("_mappingFieldsArray: " . $this->_mappingFieldsArray);
        } catch (importPosts_Exception $e) {
            echo "Error: " . $e->getMessage();
            $this->logError($e->getMessage());
            exit;
        }
    }
    
    /**
     * Import Options Settings
     */
    protected function importOptions()
    {
        $this->logError("importOptions");
        try {
            $mappingArray = explode(',', $this->_mappingFieldsArray);
            $options =    array('importallwithps' => 0,
                                'globalpassword_txt' => '',
                                'imploded_array' => $this->_uploadedHeader,
                                'h1' => $this->_h1,
                                'h2' => $this->_h2,
                                'selectedImporter' => $this->_selectedImporter,
                                'prevoptionindex' => $this->_prevoptionindex,
                                'prevoptionvalue' => $this->_prevoptionvalue,
                                'current_record' => 0,
                                'totRecords' => $this->_totRecords,
                                'tmpLoc' => $this->_tmpLoc,
                                'uploadedFile' => $this->_uploadedFile,
                                'uploaded_csv_name' => $this->_uploadedCsvName,
                                'select_delimeter' => '',
                                'stepstatus' => $this->_stepstatus,
                                'mappingArr' => $this->_mappingArr,
                                'goto_element' => '',
                                'mapping_fields_array' => $this->_mappingFieldsArray
                        );

            $mappingFields = explode(",", $this->_uploadedHeader);

            $counter = 0;
            foreach($mappingFields as $field) {
                if (in_array($field, $mappingArray)) {
                    $options['mapping' . $counter] = $field;
                    $options['textbox' . $counter] = $field;
                    $counter++;
                }
            }

            $this->_curl->post($this->_siteDomain . self::ULTIMATE_CSV_IMPORTER_IMPORT_OPTIONS_PAGE,
                    $options
                    );

            if ($this->_curl->error) {
                if ($this->_curl->response) {
                    throw new importPosts_Exception($this->_curl->response->error . ': ' . $this->_curl->response->error_description);
                } else {
                    throw new importPosts_Exception($this->_curl->curl_error_message);
                }
            }
            $this->logError(print_r($options, true));
            $this->logError("Import Options DOne.");
        } catch (importPosts_Exception $e) {
            echo "Error: " . $e->getMessage();
            $this->logError($e->getMessage());
            exit;
        }
        
    }
    
    /**
     * Import records
     */
    protected function import()
    {        
        $this->logError("import");
        try {
            $this->_curl->setOpt(CURLOPT_REFERER, $this->_siteDomain . self::ULTIMATE_CSV_IMPORTER_IMPORT_OPTIONS_PAGE);

    //        for($i=0; $i <= $this->_totRecords; $i=$i + self::RECORDS_BY_STEP) {
            $params = array('action' => 'importByRequest',
                              'postdata' => array(
                                  'dupContent' => false,
                                  'dupTitle' => false,
                                  'importlimit' => $this->_totRecords,//self::RECORDS_BY_STEP,
                                  'limit' => 0,//$i,
                                  'totRecords' => $this->_totRecords,
                                  'selectedImporter' => $this->_selectedImporter,
                                  'uploadedFile' => $this->_uploadedFile,
                                  'tmpcount' => 0,//$i
                              )
                            );

                $this->_curl->post($this->_siteDomain . "wp-admin/admin-ajax.php",
                        $params
                        );

                if ($this->_curl->error) {
                    if ($this->_curl->response) {
                        throw new importPosts_Exception($this->_curl->response->error . ': ' . $this->_curl->response->error_description);
                    } else {
                        throw new importPosts_Exception($this->_curl->curl_error_message);
                    }
                }
                $this->logError(print_r($params, true));
                $this->logError("Import DOne.");
    //        }       
        } catch (importPosts_Exception $e) {
            echo "Error: " . $e->getMessage();
            $this->logError($e->getMessage());
            exit;
        }
    }
    
    /**
     * Logout from WP
     */
    protected function logout()
    {
        $this->logError("logout");
        try {
            if ($this->_logoutUrl) {
                $this->_curl->get($this->_logoutUrl);
            } else {
                $this->_curl->get($this->_siteDomain . self::LOGOUT_PAGE);
            }
            if ($this->_curl->error) {
                if ($this->_curl->response) {
                    throw new importPosts_Exception($this->_curl->response->error . ': ' . $this->_curl->response->error_description);
                } else {
                    throw new importPosts_Exception($this->_curl->curl_error_message);
                }
            }
            
            $this->logError("Logged out.");
        } catch (importPosts_Exception $e) {
            echo "Error: " . $e->getMessage();
            $this->logError($e->getMessage());
            exit;
        }
    }
    
    protected function logError($errorMessage) {
        if (self::ENABLE_ERROR_LOG) {
            ApplicationLogger::getInstance()->custom_log('autouploader', 'autouploader.log', $errorMessage);
        }
    }

}
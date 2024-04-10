<?php
require_once(dirname(__FILE__).'/../../../config.php');
require_once(dirname(__FILE__).'/../locallib.php');
require_once(dirname(__FILE__).'/../vpl.class.php');
global $CFG;
global $USER;
require_once($CFG->libdir.'/formslib.php');
require_once($CFG->libdir.'/filelib.php');

define('PROFORMA_TASK_XML_NAMESPACES', [/* First namespace is default namespace. */'urn:proforma:v2.1']);

class mod_vpl_proforma_submission_form extends moodleform {
    protected $vpl;
    public function __construct($page, $vpl) {
        $this->vpl = $vpl;
        parent::__construct( $page );
    }
    function definition() {
        global $COURSE;
        $mform = &$this->_form;
        $id = $this->vpl->get_course_module()->id;
        $mform->addElement( 'hidden', 'id', $id );
        $mform->setType( 'id', PARAM_INT );

        $mform->addElement('header', 'taskfile', "ProForma Task File");
        $mform->addElement('filemanager', 'proformataskfileupload', 'ProForma task file',
            null, array('subdirs' => 0, 'maxbytes' => $COURSE->maxbytes, 'maxfiles' => 1));

        $mform->addElement( 'submit', 'saveoptions', get_string( 'saveoptions', VPL ) );

    }
}

require_login();
$id = required_param('id', PARAM_INT);
$vpl = new mod_vpl( $id );
$vpl->prepare_page('forms/proforma_submission.php', [ 'id' => $id ]);
$vpl->require_capability(VPL_MANAGE_CAPABILITY);
// Display page.
$vpl->print_header( get_string( 'execution', VPL ) );
$vpl->print_heading_with_help( 'executionoptions' );

$mform = new mod_vpl_proforma_submission_form('proforma_submission.php', $vpl);
if ($fromform = $mform->get_data()) {
    if (isset($fromform->saveoptions)) {

        // fetch draft item id and user context
        $draftitemid = file_get_submitted_draft_itemid('proformataskfileupload');
        $usercontext = context_user::instance($USER->id);

        // Fetch file from draft area
        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id
            , 'user', 'draft', $draftitemid, 'id', false);
        // Check if a file was uploaded
        if (count($files) > 0) {
            $file = reset($files); // First file in the list

            $filename = $file->get_filename();
            $filecontent = $file->get_content();

            $fileinfo = pathinfo($filename);
            $filetype = strtolower($fileinfo['extension']);

            if ($filetype != 'zip' && $filetype != 'xml') {
                throw new invalid_parameter_exception('Supplied file must be a xml or zip file.');
            }

            // fetch the execution file group manager and add the file to the execution files list.
            $execution_fgm = $vpl->get_execution_fgm();
            $execution_fgm->addFile("task/" . $filename, $filecontent);

            if ($filetype == 'zip') {
                $zipfilename = $filename;
                $result = array('zip' => $zipfilename);

                // Unzip file - basically copied from draftfiles_ajax.php.
                $zipper = get_file_packer('application/zip');

                // Find unused name for directory to extract the archive.
                $temppath = $fs->get_unused_dirname($usercontext->id, 'user', 'draft', $draftitemid, "/" . pathinfo($zipfilename,
                        PATHINFO_FILENAME) . '/');
                $donotremovedirs = array();
                $doremovedirs = array($temppath);
                // Extract archive and move all files from $temppath to $filepath.
                if ($file->extract_to_storage($zipper, $usercontext->id, 'user', 'draft', $draftitemid, $temppath, $USER->id)) {
                    $extractedfiles = $fs->get_directory_files($usercontext->id, 'user', 'draft', $draftitemid, $temppath, true);
                    $xtemppath = preg_quote($temppath, '|');
                    foreach ($extractedfiles as $exfile) {
                        $realpath = preg_replace('|^' . $xtemppath . '|', '/', $exfile->get_filepath());
                        if (!$exfile->is_directory()) {
                            // Set the source to the extracted file to indicate that it came from archive.
                            $exfile->set_source(serialize((object)array('source' => '/')));
                        }
                        if (!$fs->file_exists($usercontext->id, 'user', 'draft', $draftitemid, $realpath, $exfile->get_filename())) {
                            // File or directory did not exist, just move it.
                            $exfile->rename($realpath, $exfile->get_filename());
                        } else if (!$exfile->is_directory()) {
                            // File already existed, overwrite it.
                            repository::overwrite_existing_draftfile($draftitemid, $realpath, $exfile->get_filename(), $exfile->get_filepath(),
                                $exfile->get_filename());
                        } else {
                            // Directory already existed, remove temporary dir but make sure we don't remove the existing dir.
                            $doremovedirs[] = $exfile->get_filepath();
                            $donotremovedirs[] = $realpath;
                        }
                        if (!$exfile->is_directory() && $realpath == '/' && $exfile->get_filename() == 'task.xml') {
                            $result['xml'] = $exfile->get_filename();
                        }
                    }
                }
                // Remove remaining temporary directories.
                foreach (array_diff($doremovedirs, $donotremovedirs) as $filepath) {
                    $file = $fs->get_file($usercontext->id, 'user', 'draft', $draftitemid, $filepath, '.');
                    if ($file) {
                        $file->delete();
                    }
                }

                if (!array_key_exists('xml', $result)) {
                    throw new invalid_parameter_exception('Supplied zip file must contain the file task.xml.');
                }

                $file = $fs->get_file($usercontext->id, 'user', 'draft', $draftitemid, "/", $result['xml']);

                if (!$file) {
                    throw new invalid_parameter_exception('Supplied zip file doesn\'t contain task.xml file.');
                }
                $filecontent = $file->get_content();
            }

            // Create a new document with the task.xml
            $doc = new DOMDocument();
            $doc->loadXML($filecontent);

            // Find ProFormA namespace
            $chosenNamespace = '';
            foreach (PROFORMA_TASK_XML_NAMESPACES as $namespace) {
                if ($doc->getElementsByTagNameNS($namespace, "task")->length != 0) {
                    $chosenNamespace = $namespace;
                    break;
                }
            }

            $titleElement = $doc->getElementsByTagNameNS($namespace, 'title')[0];
            $descriptionElement = $doc->getElementsByTagNameNS($namespace, 'description')[0];
            // Unused since VPL doesn't have internal-description attribute
            $internalDescriptionElement = $doc->getElementsByTagNameNS($namespace, 'internal-description')[0];

            // Update the title and description values of the vpl instance.
            $instance = $vpl->get_instance();
            $instance->name = $titleElement->nodeValue;
            $instance->intro = $descriptionElement->nodeValue;
            $vpl->update();

            // Check if there are files visible by students and if yes, add them to the requested files list.
            // get required files group manager
            $required_fgm = $vpl->get_required_fgm();
            $filesElement = $doc->getElementsByTagNameNS($namespace, 'files')[0];
            foreach ($filesElement->childNodes as $fileElement) {
                if ($fileElement->nodeType === XML_ELEMENT_NODE) {
                    $visible = $fileElement->getAttribute('visible') === 'yes';
                    if ($visible) {
                        $attachedBinFiles = $fileElement->getElementsByTagNameNS($namespace, 'attached-bin-file');
                        $attachedTxtFiles = $fileElement->getElementsByTagNameNS($namespace, 'attached-txt-file');
                        if ($attachedBinFiles->length > 0 || $attachedTxtFiles->length > 0) {
                            $attachedFileValue = '';
                            if ($attachedBinFiles->length > 0) {
                                $attachedFileValue = $attachedBinFiles[0]->nodeValue;
                            } elseif ($attachedTxtFiles->length > 0) {
                                $attachedFileValue = $attachedTxtFiles[0]->nodeValue;
                            }
                            $pathInfo = pathinfo($attachedFileValue);
                            $file = $fs->get_file($usercontext->id, 'user', 'draft', $draftitemid, $pathInfo['dirname'] . "/", $pathInfo['basename']);
                            if (!$file) {
                                throw new invalid_parameter_exception('File with name ' . $attachedFileValue . ' not found');
                            }
                            $required_fgm->addFile($attachedFileValue, $file->get_content());
                        } else {
                            $embeddedBinFiles = $fileElement->getElementsByTagNameNS($namespace, 'embedded-bin-file');
                            $embeddedTxtFiles = $fileElement->getElementsByTagNameNS($namespace, 'embedded-txt-file');
                            $embeddedFileName = '';
                            $embeddedFileValue = '';
                            if ($embeddedBinFiles->length > 0) {
                                $embeddedFileName = $embeddedBinFiles[0]->getAttribute('filename');
                                $embeddedFileValue = $embeddedBinFiles[0]->nodeValue;
                            } elseif ($embeddedTxtFiles->length > 0) {
                                $embeddedFileName = $embeddedTxtFiles[0]->getAttribute('filename');
                                $embeddedFileValue = $embeddedTxtFiles[0]->nodeValue;
                            }
                            $required_fgm->addFile($embeddedFileName, $embeddedFileValue);
                        }
                    }
                }
            }
            // Clean up files in the draft file area.
            $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid);
            foreach ($files as $fi) {
                $fi->delete();
            }
        }
    }
}

$mform->display();
$vpl->print_footer();

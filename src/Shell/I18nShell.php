<?php
namespace Bit\Shell;

use Bit\Console\Shell;
use Bit\Core\Plugin;
use Bit\Utility\Inflector;
use DirectoryIterator;

/**
 * Shell for I18N management.
 *
 */
class I18nShell extends Shell
{

    /**
     * Contains tasks to load and instantiate
     *
     * @var array
     */
    public $tasks = ['Extract'];

    /**
     * Override main() for help message hook
     *
     * @return void
     */
    public function main()
    {
        $this->out('<info>I18n Shell</info>');
        $this->hr();
        $this->out('[E]xtract POT file from sources');
        $this->out('[I]nitialize a language from POT file');
        $this->out('[H]elp');
        $this->out('[Q]uit');

        $choice = strtolower($this->in('What would you like to do?', ['E', 'I', 'H', 'Q']));
        switch ($choice) {
            case 'e':
                $this->Extract->main();
                break;
            case 'i':
                $this->init();
                break;
            case 'h':
                $this->out($this->OptionParser->help());
                break;
            case 'q':
                $this->_stop();
                return;
            default:
                $this->out('You have made an invalid selection. Please choose a command to execute by entering E, I, H, or Q.');
        }
        $this->hr();
        $this->main();
    }

    /**
     * Inits PO file from POT file.
     *
     * @param string|null $language Language code to use.
     * @return int|null
     */
    public function init($language = null)
    {
        if (!$language) {
            $language = $this->in('Please specify language code, e.g. `en`, `eng`, `en_US` etc.');
        }
        if (strlen($language) < 2) {
            return $this->error('Invalid language code. Valid is `en`, `eng`, `en_US` etc.');
        }

        $this->_paths = [APP];
        if ($this->param('plugin')) {
            $plugin = Inflector::camelize($this->param('plugin'));
            $this->_paths = [Plugin::classPath($plugin)];
        }

        $response = $this->in('What folder?', null, rtrim($this->_paths[0], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Locale');
        $sourceFolder = rtrim($response, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $targetFolder = $sourceFolder . $language . DIRECTORY_SEPARATOR;
        if (!is_dir($targetFolder)) {
            mkdir($targetFolder, 0777, true);
        }

        $count = 0;
        $iterator = new DirectoryIterator($sourceFolder);
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }
            $filename = $fileinfo->getFilename();
            $newFilename = $fileinfo->getBasename('.pot');
            $newFilename = $newFilename . '.po';

            $this->createFile($targetFolder . $newFilename, file_get_contents($sourceFolder . $filename));
            $count++;
        }

        $this->out('Generated ' . $count . ' PO files in ' . $targetFolder);
    }

    /**
     * Gets the option parser instance and configures it.
     *
     * @return \Bit\Console\ConsoleOptionParser
     */
    public function getOptionParser()
    {
        $parser = parent::getOptionParser();
        $initParser = [
            'options' => [
                'plugin' => [
                    'help' => 'Plugin name.',
                    'short' => 'p'
                ],
                'force' => [
                    'help' => 'Force overwriting.',
                    'short' => 'f',
                    'boolean' => true
                ]
            ],
            'arguments' => [
                'language' => [
                    'help' => 'Two-letter language code.'
                ]
            ]
        ];

        $parser->description(
            'I18n Shell generates .pot files(s) with translations.'
        )->addSubcommand('extract', [
            'help' => 'Extract the po translations from your application',
            'parser' => $this->Extract->getOptionParser()
        ])
        ->addSubcommand('init', [
            'help' => 'Init PO language file from POT file',
            'parser' => $initParser
        ]);

        return $parser;
    }
}

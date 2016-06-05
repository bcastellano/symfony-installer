<?php

/*
 * This file is part of the Symfony Installer package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Installer\Tests;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ProcessUtils;

class IntegrationTest extends \PHPUnit_Framework_TestCase
{
    private $rootDir;
    private $fs;

    public function setUp()
    {
        $this->rootDir = realpath(__DIR__.'/../../../../');
        $this->fs = new Filesystem();

        if (!$this->fs->exists($this->rootDir.'/symfony.phar')) {
            throw new \RuntimeException(sprintf("Before running the tests, make sure that the Symfony Installer is available as a 'symfony.phar' file in the '%s' directory.", $this->rootDir));
        }
    }

    public function testDemoApplicationInstallation()
    {
        $projectDir = sprintf('%s/my_test_project', sys_get_temp_dir());
        $this->fs->remove($projectDir);

        $output = $this->runCommand(sprintf('php symfony.phar demo %s', ProcessUtils::escapeArgument($projectDir)));
        $this->assertContains('Downloading the Symfony Demo Application', $output);
        $this->assertContains('Symfony Demo Application was successfully installed.', $output);

        $output = $this->runCommand('php app/console --version', $projectDir);
        $this->assertRegExp('/Symfony version 2\.\d+\.\d+(-DEV)? - app\/dev\/debug/', $output);
    }

    /**
     * @dataProvider provideSymfonyInstallationData
     */
    public function testSymfonyInstallation($versionToInstall, $messageRegexp, $versionRegexp)
    {
        $projectDir = sprintf('%s/my_test_project', sys_get_temp_dir());
        $this->fs->remove($projectDir);

        $output = $this->runCommand(sprintf('php symfony.phar new %s %s', ProcessUtils::escapeArgument($projectDir), $versionToInstall));
        $this->assertContains('Downloading Symfony...', $output);
        $this->assertRegExp($messageRegexp, $output);

        if ('3' === substr($versionToInstall, 0, 1) || '' === $versionToInstall) {
            if (PHP_VERSION_ID < 50500) {
                $this->markTestSkipped('Symfony 3 requires PHP 5.5.9 or higher.');
            }

            $output = $this->runCommand('php bin/console --version', $projectDir);
        } else {
            $output = $this->runCommand('php app/console --version', $projectDir);
        }

        $this->assertRegExp($versionRegexp, $output);
    }

    public function testSymfonyInstallationInCurrentDirectory()
    {
        $projectDir = sprintf('%s/my_test_project', sys_get_temp_dir());
        $this->fs->remove($projectDir);
        $this->fs->mkdir($projectDir);

        $output = $this->runCommand(sprintf('php %s/symfony.phar new . 2.7.5', $this->rootDir), $projectDir);
        $this->assertContains('Downloading Symfony...', $output);

        $output = $this->runCommand('php app/console --version', $projectDir);
        $this->assertContains('Symfony version 2.7.5 - app/dev/debug', $output);
    }

    public function testSymfony3MultiAppInstallation()
    {
        if (PHP_VERSION_ID < 50500) {
            $this->markTestSkipped('Symfony 3 requires PHP 5.5.9 or higher.');
        }

        $versionToInstall = '3.0';

        $projectDir = sprintf('%s/my_test_project', sys_get_temp_dir());
        $this->fs->remove($projectDir);

        $output = $this->runCommand(sprintf('php symfony.phar new:multi-app %s %s -n', ProcessUtils::escapeArgument($projectDir), $versionToInstall));
        $this->assertContains('Downloading Symfony...', $output);
        $this->assertRegExp('/.*Symfony 3\.0\.\d+ was successfully installed.*/', $output);

        $output = $this->runCommand('php bin/app1 --version', $projectDir);
        $this->assertRegExp('/Symfony version 3\.0\.\d+(-DEV)? - app1\/dev\/debug/', $output);

        $output = $this->runCommand('php bin/app2 --version', $projectDir);
        $this->assertRegExp('/Symfony version 3\.0\.\d+(-DEV)? - app2\/dev\/debug/', $output);

        $output = $this->runServerRequest($projectDir.'/web/app1');
        $this->assertRegExp('/Your application is now ready. You can start working on it at/', $output);

        $output = $this->runServerRequest($projectDir.'/web/app2');
        $this->assertRegExp('/Your application is now ready. You can start working on it at/', $output);

        $this->runCommand('composer install', $projectDir);
    }

    /**
     * @dataProvider provideSymfony2MultiAppInstallationData
     */
    public function testSymfony2MultiAppInstallation($versionToInstall, $messageRegexp, $versionRegexp, $htmlRegexp)
    {
        $projectDir = sprintf('%s/my_test_project', sys_get_temp_dir());

        // install with old structure
        $this->fs->remove($projectDir);

        $output = $this->runCommand(sprintf('php symfony.phar new:multi-app %s %s -n', ProcessUtils::escapeArgument($projectDir), $versionToInstall));
        $this->assertContains('Downloading Symfony...', $output);
        $this->assertRegExp($messageRegexp, $output);

        $output = $this->runCommand('php apps/app1/console --version', $projectDir);
        $this->assertRegExp($versionRegexp, $output);

        $output = $this->runCommand('php apps/app2/console --version', $projectDir);
        $this->assertRegExp($versionRegexp, $output);

        $output = $this->runServerRequest($projectDir.'/web/app1');
        $this->assertRegExp($htmlRegexp, $output);

        $output = $this->runServerRequest($projectDir.'/web/app2');
        $this->assertRegExp($htmlRegexp, $output);

        $this->runCommand('composer install', $projectDir);

        // install with new structure
        $this->fs->remove($projectDir);

        $output = $this->runCommand(sprintf('php symfony.phar new:multi-app %s %s -n -d', ProcessUtils::escapeArgument($projectDir), $versionToInstall));
        $this->assertContains('Downloading Symfony...', $output);
        $this->assertRegExp($messageRegexp, $output);

        $output = $this->runCommand('php bin/app1 --version', $projectDir);
        $this->assertRegExp($versionRegexp, $output);

        $output = $this->runCommand('php bin/app2 --version', $projectDir);
        $this->assertRegExp($versionRegexp, $output);

        $output = $this->runServerRequest($projectDir.'/web/app1');
        $this->assertRegExp($htmlRegexp, $output);

        $output = $this->runServerRequest($projectDir.'/web/app2');
        $this->assertRegExp($htmlRegexp, $output);

        $this->runCommand('composer install', $projectDir);
    }

    /**
     * Run app in php built in server and return html for request
     *
     * @param string $docRoot
     * @return null|string
     */
    private function runServerRequest($docRoot)
    {
        $process = new Process('php -S localhost:8000');
        $process->setTimeout(0);
        $process->setWorkingDirectory($docRoot);
        $process->start();

        $response = null;
        $timeout = microtime(true) + 5;
        while (!$response && (microtime(true) < $timeout)) {
            $request = new Process('curl localhost:8000/app_dev.php');
            $request->run();
            $response = $request->getOutput();
        }

        return $response;
    }

    /**
     * Runs the given string as a command and returns the resulting output.
     * The CWD is set to the root project directory to simplify command paths.
     *
     * @param string $command
     *
     * @return string
     *
     * @throws ProcessFailedException in case the command execution is not successful
     */
    private function runCommand($command, $workingDirectory = null)
    {
        $process = new Process($command);
        $process->setWorkingDirectory($workingDirectory ?: $this->rootDir);
        $process->mustRun();

        return $process->getOutput();
    }

    public function provideSymfonyInstallationData()
    {
        return array(
            array(
                '',
                '/.*Symfony 3\.1\.\d+ was successfully installed.*/',
                '/Symfony version 3\.1\.\d+(-DEV)? - app\/dev\/debug/',
            ),

            array(
                'lts',
                '/.*Symfony 2\.8\.\d+ was successfully installed.*/',
                '/Symfony version 2\.8\.\d+(-DEV)? - app\/dev\/debug/',
            ),

            array(
                '2.3',
                '/.*Symfony 2\.3\.\d+ was successfully installed.*/',
                '/Symfony version 2\.3\.\d+ - app\/dev\/debug/',
            ),

            array(
                '2.5.6',
                '/.*Symfony 2\.5\.6 was successfully installed.*/',
                '/Symfony version 2\.5\.6 - app\/dev\/debug/',
            ),

            array(
                '2.7.0-BETA1',
                '/.*Symfony 2\.7\.0\-BETA1 was successfully installed.*/',
                '/Symfony version 2\.7\.0\-BETA1 - app\/dev\/debug/',
            ),

            array(
                '3.0.0-BETA1',
                '/.*Symfony dev\-master was successfully installed.*/',
                '/Symfony version 3\.0\.0\-BETA1 - app\/dev\/debug/',
            ),
        );
    }

    public function provideSymfony2MultiAppInstallationData()
    {
        return array(

            array(
                '2.8',
                '/.*Symfony 2\.8\.\d+ was successfully installed.*/',
                '/Symfony version 2\.8\.\d+(-DEV)? - app(1|2)\/dev\/debug/',
                '/Your application is now ready. You can start working on it at/'
            ),

            array(
                '2.3',
                '/.*Symfony 2\.3\.\d+ was successfully installed.*/',
                '/Symfony version 2\.3\.\d+ - app(1|2)\/dev\/debug/',
                '/Your application is now ready. You can start working on it at/'
            ),

            array(
                '2.7',
                '/.*Symfony 2\.7\.\d+ was successfully installed.*/',
                '/Symfony version 2\.7\.\d+ - app(1|2)\/dev\/debug/',
                '/Your application is now ready. You can start working on it at/'
            ),

        );
    }
}

<?php

namespace Shulard\BuildArtifacts\Console\Commands\Gitlab;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// wraps env()
function env() {
    return call_user_func('getenv', func_get_arg(0)) ? : func_get_arg(1);
}

function base_path() {
    return dirname(__DIR__);
}

class Download extends Command
{
    /**
     * Gitlab API URL to project build collection
     */
    const PROJECT_BUILD_URL = "%s/api/v4/projects/%s/jobs?scope[]=success";

    /**
     * Gitlab API URL to download artifact for a given build
     */
    const ARTIFACT_BUILD_URL = "%s/api/v4/projects/%s/artifacts/%d/artifacts/%s/download?job=%s";

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'artifact:gitlab:download '.
        '{--token=        : Gitlab authentication token.} '.
        '{--project=      : Project identifier on which artifact was built.} '.
        '{--in=.          : Path where the artifact must be extracted.} '.
        '{--ref=          : Repository ref the build was ran on.} '.
        '{--tag=          : Repository tag the build was ran on.} '.
        '{--stage=prepare : Build stage from which artifact is downloaded}'.
        '{--perpage=50    : Number of items to be retrieve from API}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retrieve a Gitlab artifact and install it in the project';

    protected function configure()
    {
        $this
            ->setName('artifact:gitlab:download')
            ->setDescription('Download artifacts from GitLab CI.')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Gitlab authentication token')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project identifier on which artifact was built')
            ->addOption('in', null, InputOption::VALUE_REQUIRED, 'Path where the artifact must be extracted', '.')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Repository ref the build was ran on')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Repository tag the build was ran on')
            ->addOption('stage', null, InputOption::VALUE_REQUIRED, 'Build stage from which artifact is downloaded', 'prepare');
    }

    public function __construct($composer, $io) {
        $this->composer = $composer;
        $this->io = $io;
        parent::__construct();
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->handle();
    }

    // wraps Symfony Console InputInterface::getOption()
    public function option() {
        return call_user_func_array([$this->input, 'getOption'], func_get_args());
    }

    public function comment($str) { $this->io->write('<comment>' . $str . '</comment>'); }
    public function info($str) { $this->io->write('<info>' . $str . '</info>'); }
    public function error($str) { $this->io->writeError($str); }

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \RuntimeException
     */
    public function handle()
    {
        $url = env('GITLAB_API', 'https://gitlab.com');

        if (null === $project = $this->option('project')) {
            $project = env('GITLAB_PROJECT');
        }
        if (null === $token = $this->option('token')) {
            $token = env('GITLAB_TOKEN');
        }

        $in = realpath($this->option('in'));
        if (!is_dir($in) || !is_writable($in)) {
            throw new \RuntimeException('Invalid --in option, must be a valid path !');
        }
        if (strpos($in, base_path()) === false) {
            throw new \RuntimeException('--in option must define a folder inside current project !');
        }

        $build = $this->getLatestSuccessfulBuild(
            $url,
            $token,
            $project,
            $this->option('stage'),
            $this->option('ref'),
            $this->option('tag')
        );

        $path = base_path().'/storage/artifact-'.$build['id'].'.zip';
        try {
            $zip = fopen($path, 'w+');
            $this->api(
                sprintf(self::ARTIFACT_BUILD_URL, $url, urlencode($project), $build['id']),
                $token,
                $zip
            );
            fclose($zip);
        } catch (\Exception $error) {
            $this->error('Can\'t download artifact to '.$path);
            fclose($zip);
        }

        $this->installArtifact($path, $in);
        unlink($path);
    }

    /**
     * Retrieve latest successful build information
     * @param  string $url      Gitlab API root URL
     * @param  string $token    Gitlab API Token
     * @param  integer $project Project identifier
     * @return array
     * @throws \RuntimeException
     */
    private function getLatestSuccessfulBuild($url, $token, $project, $stage, $ref, $tag)
    {
        $result = $this->apiJson(
            sprintf(self::PROJECT_BUILD_URL, $url, urlencode($project)),
            $token
        );

        $result = collect($result)->reject(function ($item) use ($stage, $ref, $tag) {
            return $item['status'] !== 'success'
                || $item['stage'] !== $stage
                || (null !== $ref && $item['ref'] !== $ref)
                || (null !== $tag && $item['tag'] !== $tag);
        })->first();
        if (null === $result) {
            throw new \RuntimeException('Can\'t find a successful build in the project...');
        }

        $this->info(sprintf(
            "Latest build [%s]\n- ref: %s\n- stage: %s\n- at: %s\n- runner: %d -> %s\n- triggered by: %s",
            $result['id'],
            $result['ref'],
            $result['stage'],
            $result['created_at'],
            $result['runner']['id'],
            $result['runner']['description'],
            $result['user']['username']
        ));

        return $result;
    }

    /**
     * Build a curl handle capable to make Gitlab API request
     * @param  string $url
     * @param  string $token Gitlab API Tokan
     * @return resource
     * @throws \RuntimeException
     */
    private function api($url, $token, $file = null)
    {
        $h = curl_init();
        $this->comment($url);
        if (null === $file) {
            curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
        } else {
            curl_setopt($h, CURLOPT_FILE, $file);
        }
        curl_setopt($h, CURLOPT_URL, $url);
        curl_setopt($h, CURLOPT_HTTPHEADER, [
            sprintf("PRIVATE-TOKEN: %s", $token)
        ]);

        $raw = curl_exec($h);
        if (curl_getinfo($h, CURLINFO_HTTP_CODE) - 200 > 100) {
            throw new \RuntimeException(sprintf(
                "Error during API call\n[%d] -> %s",
                curl_getinfo($h, CURLINFO_HTTP_CODE),
                $raw
            ));
        }
        curl_close($h);

        return $raw;
    }

    /**
     * Make an API call to retrieve JSON formatted results
     * @param  string $url
     * @param  string $token
     * @return array
     * @throws \RuntimeException
     */
    private function apiJson($url, $token)
    {
        $raw = $this->api($url, $token);

        if (null === $result = json_decode($raw, true)) {
            throw new \RuntimeException("Can't decode Gitlab API response...");
        }
        return $result;
    }

    /**
     * Install artifact in the specified folder
     * @param  string $path
     * @param  string $in
     * @throws \RuntimeException
     */
    private function installArtifact($path, $in)
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) === true) {
            $zip->extractTo($in);
            $zip->close();

            $this->info('Artifact extracted in : '.$in);
        } else {
            throw new \RuntimeException(sprintf(
                'Can\'t extract ZIP file "%s" in "%s"',
                basename($path),
                $in
            ));
        }
    }
}

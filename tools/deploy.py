"""Deploy the project to the server from .env (SERVER_IP, SERVER_SSH_LOGIN, SERVER_SSH_PASSWORD).

Usage:  python tools/deploy.py [--no-build]

Uploads the working tree (files tracked by git or not ignored), writes the server .env
(without the SSH credentials) and runs `docker compose up -d --build` in /opt/homecam.
"""
import io, os, subprocess, sys, tarfile, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import remote

ROOT = remote.ROOT
REMOTE_DIR = '/opt/homecam'
SKIP_KEYS = ('SERVER_SSH_LOGIN', 'SERVER_SSH_PASSWORD')


def files():
    out = subprocess.check_output(['git', 'ls-files', '-co', '--exclude-standard', '-z'], cwd=ROOT)
    return [f for f in out.decode('utf-8').split('\0') if f and os.path.isfile(os.path.join(ROOT, f))]


def main():
    env = remote.load_env()
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode='w:gz') as tar:
        for f in files():
            if f == '.env':
                continue
            tar.add(os.path.join(ROOT, f), arcname=f)
        lines = []
        with open(os.path.join(ROOT, '.env'), encoding='utf-8') as fh:
            for line in fh:
                if not any(line.startswith(k + '=') for k in SKIP_KEYS):
                    lines.append(line)
        data = ''.join(lines).encode('utf-8')
        info = tarfile.TarInfo('.env')
        info.size = len(data)
        info.mode = 0o600
        info.mtime = int(time.time())
        tar.addfile(info, io.BytesIO(data))
    buf.seek(0)

    c = remote.client(env)
    sftp = c.open_sftp()
    remote_tmp = f"/tmp/homecam-deploy-{int(time.time())}.tgz"
    sftp.putfo(buf, remote_tmp, confirm=False)
    sftp.close()
    pw = env['SERVER_SSH_PASSWORD']
    data_dir = env.get('SERVER_CAM_DATA_DIR', '/store/camdata')
    build = '' if '--no-build' in sys.argv else '--build'
    script = f"""
set -e
mkdir -p {REMOTE_DIR} {data_dir}
cd {REMOTE_DIR}
find . -mindepth 1 -maxdepth 1 ! -name state ! -name camdata -exec rm -rf {{}} +
tar xzf {remote_tmp} -C {REMOTE_DIR}
rm -f {remote_tmp}
mkdir -p state
docker compose up -d {build} --remove-orphans
docker compose ps
"""
    sys.exit(remote.run(c, script, pw))


if __name__ == '__main__':
    main()

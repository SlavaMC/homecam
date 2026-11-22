"""Helper: run commands / upload files to the server using creds from ../.env.
Usage:
  python tools/remote.py exec "command"
  python tools/remote.py sudo "command"
  python tools/remote.py put <local> <remote>
"""
import os, re, sys, paramiko

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

def load_env():
    env = {}
    with open(os.path.join(ROOT, '.env'), encoding='utf-8') as f:
        for line in f:
            m = re.match(r'\s*([A-Z0-9_]+)\s*[=-]\s*(.*?)\s*$', line)
            if m and not line.lstrip().startswith('#'):
                env[m.group(1)] = m.group(2).strip('"\'')
    return env

def client(env):
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect(env['SERVER_IP'], username=env['SERVER_SSH_LOGIN'], password=env['SERVER_SSH_PASSWORD'], timeout=15)
    return c

def run(c, cmd, sudo_pw=None):
    if sudo_pw:
        cmd = "sudo -S -p '' bash -c " + "'" + cmd.replace("'", "'\''") + "'"
    stdin, stdout, stderr = c.exec_command(cmd, get_pty=False)
    if sudo_pw:
        stdin.write(sudo_pw + '\n'); stdin.flush()
    for line in iter(stdout.readline, ''):
        sys.stdout.buffer.write(line.encode('utf-8', 'replace')); sys.stdout.flush()
    err = stderr.read().decode('utf-8', 'replace')
    if err:
        sys.stderr.write(err)
    return stdout.channel.recv_exit_status()

if __name__ == '__main__':
    env = load_env()
    c = client(env)
    mode = sys.argv[1]
    if mode == 'exec':
        sys.exit(run(c, sys.argv[2]))
    elif mode == 'sudo':
        sys.exit(run(c, sys.argv[2], env['SERVER_SSH_PASSWORD']))
    elif mode == 'put':
        sftp = c.open_sftp(); sftp.put(sys.argv[2], sys.argv[3]); sftp.close()

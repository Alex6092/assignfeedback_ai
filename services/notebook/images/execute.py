"""Executed ONLY in the disposable, offline, unprivileged grading container."""
import os
import time
import signal
from pathlib import Path
import nbformat
from nbclient import NotebookClient
from jupyter_client import KernelManager

# PID 1 exits even if the integration service disappears while the kernel is running.
signal.alarm(int(os.environ.get('COPY_TIMEOUT', '300')) + 20)

for _ in range(100):
    if Path("/input/ready").exists():
        break
    time.sleep(.1)
else:
    raise RuntimeError("Missing input")

nb = nbformat.read("/input/assignment.ipynb", as_version=4)
km = KernelManager(kernel_name='grading', connection_file='/tmp/kernel.json')
km.write_connection_file()
os.chmod(km.connection_file, 0o644)
km.start_kernel(cwd='/work')
try:
    client = NotebookClient(nb, km=km, timeout=int(os.environ.get("CELL_TIMEOUT", 60)),
                            allow_errors=True, interrupt_on_timeout=True,
                            error_on_timeout={"ename": "Timeout", "evalue": "Cell time limit", "traceback": []})
    client.execute()
finally:
    km.shutdown_kernel(now=True)
# Keep bounded output; hidden test details are removed by the controller.
for cell in nb.cells:
    if cell.cell_type == "code":
        cell.outputs = [o for o in cell.outputs if o.output_type == "error"]
nbformat.write(nb, "/results/executed.ipynb")
Path('/results/complete').touch()
# Keep tmpfs mounted until the controller has collected the result and removes us.
time.sleep(360)

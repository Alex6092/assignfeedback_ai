import os
c = get_config()  # noqa: F821
c.ServerApp.root_dir = '/home/jovyan/work'
c.ServerApp.open_browser = False
c.ServerApp.terminals_enabled = False
c.ServerApp.shutdown_no_activity_timeout = 1800
c.MappingKernelManager.cull_idle_timeout = 1800
c.MappingKernelManager.cull_interval = 60
c.MappingKernelManager.cull_connected = True
c.ServerApp.tornado_settings = {'page_config_data': {
    'moodleReturnUrl': os.getenv('MOODLE_RETURN_URL', ''),
    'moodleTeacher': os.getenv('MOODLE_TEACHER', '0'),
}}

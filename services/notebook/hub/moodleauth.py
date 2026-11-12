import json
import os
from urllib.parse import urlsplit
from tornado.httpclient import AsyncHTTPClient, HTTPRequest, HTTPClientError
from tornado.web import HTTPError
from jupyterhub.auth import Authenticator
from jupyterhub.handlers.base import BaseHandler


class MoodleLogin(BaseHandler):
    async def get(self):
        self.redirect(os.environ['MOODLE_URL'])

    def check_xsrf_cookie(self):
        # The ticket is delivered by a POST form from Moodle, never a URL.
        expected = urlsplit(os.environ['MOODLE_URL'])
        origin = self.request.headers.get('Origin', '')
        if origin != expected.scheme + '://' + expected.netloc:
            raise HTTPError(403)

    async def post(self):
        user = await self.login_user({'code': self.get_body_argument('code')})
        if not user:
            raise HTTPError(403)
        self.redirect(self.hub.base_url + 'spawn')


class MoodleAuthenticator(Authenticator):
    enable_auth_state = True
    allow_all = True  # Admission is exclusively determined by Moodle's one-use ticket.

    def get_handlers(self, app):
        return [('/moodle', MoodleLogin)]

    def login_url(self, base_url):
        return base_url.rstrip('/') + '/moodle'

    async def authenticate(self, handler, data):
        if not data or not data.get('code'):
            return None
        try:
            response = await AsyncHTTPClient().fetch(HTTPRequest(
                os.environ['WORKSPACE_API_URL'] + '/v1/launch', method='POST',
                headers={'Authorization': 'Bearer ' + os.environ['WORKSPACE_TOKEN'], 'Content-Type': 'application/json'},
                body=json.dumps({'code': data['code']}), request_timeout=45))
        except HTTPClientError:
            raise HTTPError(403, 'Open this notebook from its Moodle assignment')
        return json.loads(response.body)

    async def refresh_user(self, user, handler=None):
        state = await user.get_auth_state()
        if not state:
            return False
        try:
            response = await AsyncHTTPClient().fetch(HTTPRequest(
                os.environ['MOODLE_URL'].rstrip('/') + '/local/notebook/api.php', method='POST',
                headers={'Authorization': 'Bearer ' + os.environ['MOODLE_TOKEN'], 'Content-Type': 'application/json'},
                body=json.dumps({'action': 'access', 'lease': state['lease']}), request_timeout=5))
            return json.loads(response.body).get('allowed') is True
        except Exception:
            return False

    refresh_pre_spawn = True
    auth_refresh_age = 30

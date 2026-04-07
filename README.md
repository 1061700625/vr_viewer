# vr_viewer
单文件实现的VR全景视频浏览网页

- index.html：支持上传视频后浏览、拖动视角
- index.php：支持上传视频后浏览、拖动视角、缩放、分享链接、手机姿态控制视角(需开启获取方向权限)等

[demo](https://www.xfxuezhang.cn/web/vrview/?ref=demo)

---

对于PHP，记得修改php.ini配置：
```bash
sudo vim /etc/php/8.1/apache2/php.ini
```
```bash
file_uploads = On
upload_max_filesize = 500M
post_max_size = 520M
max_execution_time = 300
max_input_time = 300
```

然后重启PHP和Apache：
```bash
sudo systemctl restart php8.1-fpm
sudo systemctl restart apache2
```

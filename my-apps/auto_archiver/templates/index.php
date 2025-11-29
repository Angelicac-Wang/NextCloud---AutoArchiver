<?php
script('auto_archiver', 'auto_archiver-main');
style('auto_archiver', 'cold_palace');
?>

<div id="app-cold-palace" data-app="cold_palace">
    <div id="app-navigation">
        <ul>
            <li><a href="#">封存檔案</a></li>
            <li><a href="#">最近封存</a></li>
            <li><a href="#">全部檔案</a></li>
        </ul>
    </div>

    <div id="app-content">
        <div id="app-content-wrapper">
            <h2>冷宮區 - 封存檔案管理</h2>
            <p>這裡是封存的檔案存放區域</p>

            <div id="cold-palace-content">
                <!-- Vue.js 將在這裡渲染內容 -->
            </div>
        </div>
    </div>
</div>

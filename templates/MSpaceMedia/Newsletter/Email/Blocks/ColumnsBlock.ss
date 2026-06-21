<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td style="$InlineStyle">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <% loop $Columns %>
                    <td class="nl-col" width="{$Up.ColumnWidthPercent}%" valign="top" style="width:{$Up.ColumnWidthPercent}%;padding:0 8px;font-family:$Up.EffectiveFont;font-size:15px;line-height:1.5;color:$Up.EffectiveTextColor;">
                        $Content
                    </td>
                <% end_loop %>
            </tr>
        </table>
    </td>
</tr>

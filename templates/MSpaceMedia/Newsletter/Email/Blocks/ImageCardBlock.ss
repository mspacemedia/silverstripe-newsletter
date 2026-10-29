<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td class="$BlockClass" style="$InlineStyle;font-family:$EffectiveFont;color:$EffectiveTextColor;">
        <% with $ScaledImage %>
            <img src="$AbsoluteURL" alt="$Up.Heading.ATT" width="$Width" style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;height:auto;<% if $Up.Alignment == center %>margin:0 auto;<% end_if %>" />
        <% end_with %>
        <% if $Heading %><h3 class="nl-h3" style="{$HeadingStyle(3)}margin:18px 0 8px;">$Heading.XML</h3><% end_if %>
        <% if $Content %><div style="font-size:15px;line-height:1.6;">$RenderedContent</div><% end_if %>
        <% if $ButtonURL %><div style="margin-top:16px;">$RenderedButton</div><% end_if %>
    </td>
</tr>
